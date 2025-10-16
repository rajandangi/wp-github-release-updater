<?php
/**
 * GitHub API client for WP GitHub Release Updater
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub API client class
 */
class GitHubAPI {

	/**
	 * GitHub API base URL
	 */
	private const API_BASE_URL = 'https://api.github.com';

	/**
	 * Config instance
	 *
	 * @var Config|null
	 */
	private $config;

	/**
	 * Repository owner
	 *
	 * @var string|null
	 */
	private $owner;

	/**
	 * Repository name
	 *
	 * @var string|null
	 */
	private $repo;

	/**
	 * Access token for private repositories
	 *
	 * @var string|null
	 */
	private $access_token;

	/**
	 * Constructor
	 *
	 * @param Config $config Configuration instance
	 */
	public function __construct( $config ) {
		$this->config = $config;
		$this->loadConfiguration();
	}

	/**
	 * Load configuration from WordPress options
	 */
	private function loadConfiguration() {
		$repository_url = $this->config->getOption( 'repository_url', '' );
		// Use Config's decryption method to get access token
		$this->access_token = $this->config->getAccessToken();

		if ( ! empty( $repository_url ) ) {
			$this->parseRepositoryUrl( $repository_url );
		}
	}

	/**
	 * Parse repository URL to extract owner and repo
	 *
	 * @param string $url Repository URL
	 * @return bool Success status
	 */
	private function parseRepositoryUrl( $url ) {
		// Support both owner/repo and full GitHub URLs
		$patterns = array(
			'/^([a-zA-Z0-9_.-]+)\/([a-zA-Z0-9_.-]+)$/', // owner/repo format
			'/github\.com\/([a-zA-Z0-9_.-]+)\/([a-zA-Z0-9_.-]+?)(?:\.git)?(?:\/)?$/', // Full URL format
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, trim( $url ), $matches ) ) {
				$this->owner = $matches[1];
				// Remove .git suffix if present
				$repo = $matches[2];
				if ( substr( $repo, -4 ) === '.git' ) {
					$repo = substr( $repo, 0, -4 );
				}
				$this->repo = $repo;
				return true;
			}
		}

		return false;
	}

	/**
	 * Set repository configuration
	 *
	 * @param string $owner Repository owner
	 * @param string $repo Repository name
	 * @param string $access_token Optional access token
	 */
	public function setRepository( $owner, $repo, $access_token = '' ) {
		$this->owner = sanitize_text_field( $owner );

		// Remove .git suffix if present
		$repo = sanitize_text_field( $repo );
		if ( substr( $repo, -4 ) === '.git' ) {
			$repo = substr( $repo, 0, -4 );
		}

		$this->repo         = $repo;
		$this->access_token = sanitize_text_field( $access_token );
	}

	/**
	 * Get latest release from GitHub
	 *
	 * @return array|WP_Error Release data or error
	 */
	public function getLatestRelease() {
		if ( empty( $this->owner ) || empty( $this->repo ) ) {
			return new \WP_Error( 'invalid_repo', 'Repository owner and name must be configured' );
		}

		$url = self::API_BASE_URL . "/repos/{$this->owner}/{$this->repo}/releases/latest";

		return $this->makeRequest( $url );
	}

	/**
	 * Test repository access
	 *
	 * @return bool|WP_Error Success status or error
	 */
	public function testRepositoryAccess() {
		if ( empty( $this->owner ) || empty( $this->repo ) ) {
			return new \WP_Error( 'invalid_repo', 'Repository owner and name must be configured' );
		}

		$url    = self::API_BASE_URL . "/repos/{$this->owner}/{$this->repo}";
		$result = $this->makeRequest( $url );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Make HTTP request to GitHub API
	 *
	 * @param string $url API endpoint URL
	 * @param array  $args Additional request arguments
	 * @return array|WP_Error Response data or error
	 */
	private function makeRequest( $url, $args = array() ) {
		// Generate cache key based on URL and auth status
		$cache_key = $this->getCacheKey( $url );

		// Try to get cached response
		$cached_response = $this->getCachedResponse( $cache_key );
		if ( false !== $cached_response ) {
			return $cached_response;
		}

		$default_args = array(
			'timeout'    => 30,
			'user-agent' => $this->config->getPluginName() . '/' . $this->config->getPluginVersion(),
			'headers'    => array(
				'Accept' => 'application/vnd.github.v3+json',
			),
		);

		// Add authorization header if token is available
		if ( ! empty( $this->access_token ) ) {
			$default_args['headers']['Authorization'] = 'token ' . $this->access_token;
		}

		$args = wp_parse_args( $args, $default_args );

		// Apply filters to modify request arguments
		$args = apply_filters( $this->config->getPluginSlug() . '_request_args', $args, $url );

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );

		// Handle different response codes
		switch ( $response_code ) {
			case 200:
				$data = json_decode( $body, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					return new \WP_Error( 'json_error', 'Invalid JSON response from GitHub API' );
				}

				// Cache successful response
				$this->setCachedResponse( $cache_key, $data );

				return $data;

			case 401:
				return new \WP_Error( 'unauthorized', 'GitHub API authentication failed. Check your access token.' );

			case 403:
				$rate_limit_remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
				if ( $rate_limit_remaining === '0' ) {
					$error = new \WP_Error( 'rate_limit', 'GitHub API rate limit exceeded. Try again later.' );
					// Cache rate limit errors for 1 hour
					$this->setCachedResponse( $cache_key, $error, 3600 );
					return $error;
				}
				return new \WP_Error( 'forbidden', 'Access to GitHub repository is forbidden.' );

			case 404:
				return new \WP_Error( 'not_found', 'GitHub repository or release not found.' );

			default:
				return new \WP_Error(
					'api_error',
					sprintf( 'GitHub API request failed with status code: %d', $response_code )
				);
		}
	}

	/**
	 * Generate cache key for a URL
	 *
	 * @param string $url API endpoint URL
	 * @return string Cache key
	 */
	private function getCacheKey( $url ) {
		$key_parts = array(
			$url,
			! empty( $this->access_token ) ? 'authed' : 'public',
		);

		$hash = md5( implode( '|', $key_parts ) );
		return $this->config->getCachePrefix() . $hash;
	}

	/**
	 * Get cached response
	 *
	 * @param string $cache_key Cache key
	 * @return mixed|false Cached data or false if not found
	 */
	private function getCachedResponse( $cache_key ) {
		return get_transient( $cache_key );
	}

	/**
	 * Set cached response
	 *
	 * @param string $cache_key Cache key
	 * @param mixed  $data Data to cache
	 * @param int    $duration Cache duration in seconds (optional, uses config default)
	 * @return bool Success status
	 */
	private function setCachedResponse( $cache_key, $data, $duration = null ) {
		if ( null === $duration ) {
			$duration = $this->config->getCacheDuration();
		}

		return set_transient( $cache_key, $data, $duration );
	}

	/**
	 * Check if any cache exists
	 *
	 * @return bool True if cache exists
	 */
	public function hasCachedData() {
		global $wpdb;
		$cache_prefix = $this->config->getCachePrefix();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $cache_prefix ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $count > 0;
	}

	/**
	 * Clear all GitHub API cache
	 *
	 * @param string $endpoint Optional specific endpoint to clear
	 * @return void
	 */
	public function clearCache( $endpoint = null ) {
		$cache_prefix = $this->config->getCachePrefix();

		if ( null === $endpoint ) {
			// Clear all cache entries with our prefix
			// We need to use direct DB queries as WordPress doesn't provide
			// a built-in function to delete transients by prefix pattern
			global $wpdb;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// Delete transient values
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_' . $cache_prefix ) . '%'
				)
			);

			// Delete transient timeout entries
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( '_transient_timeout_' . $cache_prefix ) . '%'
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			// Clear specific endpoint cache
			$cache_key = $this->getCacheKey( $endpoint );
			delete_transient( $cache_key );
		}
	}
}
