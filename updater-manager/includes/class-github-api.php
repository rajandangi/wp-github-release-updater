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
		$repository_url     = $this->config->getOption( 'repository_url', '' );
		$this->access_token = $this->config->getOption( 'access_token', '' );

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
				return $data;

			case 401:
				return new \WP_Error( 'unauthorized', 'GitHub API authentication failed. Check your access token.' );

			case 403:
				$rate_limit_remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
				if ( $rate_limit_remaining === '0' ) {
					return new \WP_Error( 'rate_limit', 'GitHub API rate limit exceeded. Try again later.' );
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
}
