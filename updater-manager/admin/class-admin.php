<?php
/**
 * Admin class for WP GitHub Release Updater
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin class
 */
class Admin {

	/**
	 * Config instance
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * GitHub API instance
	 *
	 * @var GitHubAPI
	 */
	private $github_api;

	/**
	 * Updater instance
	 *
	 * @var Updater
	 */
	private $updater;

	/**
	 * Constructor
	 *
	 * @param Config    $config Configuration instance.
	 * @param GitHubAPI $github_api GitHub API instance.
	 * @param Updater   $updater Updater instance.
	 */
	public function __construct( $config, $github_api, $updater ) {
		$this->config     = $config;
		$this->github_api = $github_api;
		$this->updater    = $updater;

		$this->initHooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function initHooks() {
		add_action( 'admin_menu', array( $this, 'addAdminMenu' ) );
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueScripts' ) );
		add_action( 'wp_ajax_' . $this->config->getAjaxCheckAction(), array( $this, 'ajaxCheckForUpdates' ) );
		add_action( 'wp_ajax_' . $this->config->getAjaxUpdateAction(), array( $this, 'ajaxPerformUpdate' ) );
		add_action( 'wp_ajax_' . $this->config->getAjaxTestRepoAction(), array( $this, 'ajaxTestRepository' ) );
		add_action( 'admin_notices', array( $this, 'showAdminNotices' ) );
	}

	/**
	 * Add admin menu page
	 */
	public function addAdminMenu() {
		add_management_page(
			$this->config->getPageTitle(),
			$this->config->getMenuTitle(),
			$this->config->getCapability(),
			$this->config->getSettingsPageSlug(),
			array( $this, 'displaySettingsPage' )
		);
	}

	/**
	 * Register plugin settings
	 */
	public function registerSettings() {
		register_setting(
			$this->config->getSettingsGroup(),
			$this->config->getOptionName( 'repository_url' ),
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			$this->config->getSettingsGroup(),
			$this->config->getOptionName( 'access_token' ),
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitizeAccessToken' ),
				'default'           => '',
			)
		);

		// Add settings sections
		add_settings_section(
			$this->config->getSettingsSection(),
			'Repository Configuration',
			array( $this, 'settingsSectionCallback' ),
			$this->config->getSettingsPageSlug()
		);

		// Repository URL field
		add_settings_field(
			'repository_url',
			'Repository URL',
			array( $this, 'repositoryUrlFieldCallback' ),
			$this->config->getSettingsPageSlug(),
			$this->config->getSettingsSection()
		);

		// Access token field
		add_settings_field(
			'access_token',
			'Access Token',
			array( $this, 'accessTokenFieldCallback' ),
			$this->config->getSettingsPageSlug(),
			$this->config->getSettingsSection()
		);
	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Current admin page hook
	 */
	public function enqueueScripts( $hook ) {
		// Determine the menu parent prefix for the page hook
		$menu_parent_prefix = str_replace( '.php', '', $this->config->getMenuParent() );
		$expected_hook      = $menu_parent_prefix . '_page_' . $this->config->getSettingsPageSlug();

		if ( $hook !== $expected_hook ) {
			return;
		}

		wp_enqueue_script(
			$this->config->getScriptHandle(),
			$this->config->getUpdaterUrl() . 'admin/js/admin.js',
			array(),
			$this->config->getPluginVersion(),
			true
		);

		wp_enqueue_style(
			$this->config->getStyleHandle(),
			$this->config->getUpdaterUrl() . 'admin/css/admin.css',
			array(),
			$this->config->getPluginVersion()
		);

		// Localize script for AJAX
		wp_localize_script(
			$this->config->getScriptHandle(),
			'wpGitHubUpdater',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( $this->config->getNonceName() ),
				'actions' => array(
					'check'    => $this->config->getAjaxCheckAction(),
					'update'   => $this->config->getAjaxUpdateAction(),
					'testRepo' => $this->config->getAjaxTestRepoAction(),
				),
				'strings' => array(
					'checking'       => 'Checking for updates...',
					'updating'       => 'Updating plugin...',
					'testing'        => 'Testing repository access...',
					'error'          => 'An error occurred. Please try again.',
					'confirm_update' => 'Are you sure you want to update the plugin? This action cannot be undone.',
					'success'        => 'Operation completed successfully.',
				),
			)
		);
	}

	/**
	 * Display settings page
	 */
	public function displaySettingsPage() {
		if ( ! current_user_can( $this->config->getCapability() ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}

		include $this->config->getUpdaterDir() . 'admin/views/settings.php';
	}

	/**
	 * Settings section callback
	 */
	public function settingsSectionCallback() {
		echo '<p>Configure your GitHub repository for automatic updates.</p>';
	}

	/**
	 * Repository URL field callback
	 */
	public function repositoryUrlFieldCallback() {
		$value = $this->config->getOption( 'repository_url', '' );
		echo '<input type="text" id="repository_url" name="' . esc_attr( $this->config->getOptionName( 'repository_url' ) ) . '" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="owner/repo or https://github.com/owner/repo" />';
		echo '<p class="description">Enter the GitHub repository URL or owner/repo format.</p>';
	}

	/**
	 * Access token field callback
	 */
	public function accessTokenFieldCallback() {
		// Get decrypted token to check if one exists
		$decrypted_token = $this->config->getAccessToken();
		$masked_value    = ! empty( $decrypted_token ) ? str_repeat( '*', min( strlen( $decrypted_token ), 40 ) ) : '';

		echo '<input type="password" id="access_token" name="' . esc_attr( $this->config->getOptionName( 'access_token' ) ) . '" value="' . esc_attr( $masked_value ) . '" class="regular-text" placeholder="ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" />';
		echo '<p class="description">Optional: Personal Access Token for private repositories. Leave empty for public repositories.</p>';
		echo '<p class="description"><strong>Security:</strong> Token is encrypted before storage. If you see asterisks, a token is already saved.</p>';
		echo '<p class="description"><strong>To update:</strong> Enter a new token to replace the existing one.</p>';
	}

	/**
	 * Sanitize access token
	 *
	 * @param string $token Access token
	 * @return string Encrypted token
	 */
	public function sanitizeAccessToken( $token ) {
		// If token is masked (all asterisks), keep the existing encrypted token
		if ( preg_match( '/^\*+$/', $token ) ) {
			return $this->config->getOption( 'access_token', '' );
		}

		// If empty, delete the token
		if ( empty( trim( $token ) ) ) {
			return '';
		}

		// Sanitize then encrypt the new token
		$sanitized_token = sanitize_text_field( $token );

		// Return the encrypted token directly
		// WordPress will save this via update_option
		return $this->config->encrypt( $sanitized_token );
	}

	/**
	 * AJAX handler for checking updates
	 */
	public function ajaxCheckForUpdates() {
		// Verify nonce and permissions
		if ( ! wp_verify_nonce( $_POST['nonce'], $this->config->getNonceName() ) || ! current_user_can( $this->config->getCapability() ) ) {
			wp_die( 'Security check failed' );
		}

		// Reload GitHub API configuration
		$this->github_api->__construct( $this->config );

		$result = $this->updater->checkForUpdates();

		wp_send_json( $result );
	}

	/**
	 * AJAX handler for performing updates
	 */
	public function ajaxPerformUpdate() {
		// Verify nonce and permissions
		if ( ! wp_verify_nonce( $_POST['nonce'], $this->config->getNonceName() ) || ! current_user_can( $this->config->getCapability() ) ) {
			wp_die( 'Security check failed' );
		}

		$result = $this->updater->performUpdate();

		wp_send_json( $result );
	}

	/**
	 * AJAX handler for testing repository access
	 */
	public function ajaxTestRepository() {
		// Verify nonce and permissions
		if ( ! wp_verify_nonce( $_POST['nonce'], $this->config->getNonceName() ) || ! current_user_can( $this->config->getCapability() ) ) {
			wp_die( 'Security check failed' );
		}

		// Get posted settings
		$repository_url = sanitize_text_field( $_POST['repository_url'] ?? '' );
		$access_token   = sanitize_text_field( $_POST['access_token'] ?? '' );

		// If token is masked, get the existing one
		if ( preg_match( '/^\*+$/', $access_token ) ) {
			$access_token = $this->config->getOption( 'access_token', '' );
		}

		// Test with the provided settings
		$test_api = new GitHubAPI( $this->config );

		// Parse repository URL and remove .git suffix if present
		if ( preg_match( '/^([a-zA-Z0-9_.-]+)\/([a-zA-Z0-9_.-]+)$/', $repository_url, $matches ) ) {
			$test_api->setRepository( $matches[1], $matches[2], $access_token );
		} elseif ( preg_match( '/github\.com\/([a-zA-Z0-9_.-]+)\/([a-zA-Z0-9_.-]+?)(?:\.git)?(?:\/)?$/', $repository_url, $matches ) ) {
			$test_api->setRepository( $matches[1], $matches[2], $access_token );
		} else {
			wp_send_json(
				array(
					'success' => false,
					'message' => 'Invalid repository URL format.',
				)
			);
			return;
		}

		$test_result = $test_api->testRepositoryAccess();

		if ( is_wp_error( $test_result ) ) {
			wp_send_json(
				array(
					'success' => false,
					'message' => $test_result->get_error_message(),
				)
			);
		} else {
			wp_send_json(
				array(
					'success' => true,
					'message' => 'Repository access successful!',
				)
			);
		}
	}

	/**
	 * Show admin notices
	 */
	public function showAdminNotices() {
		$screen = get_current_screen();

		// Determine the menu parent prefix for the page hook
		$menu_parent_prefix = str_replace( '.php', '', $this->config->getMenuParent() );
		$expected_screen_id = $menu_parent_prefix . '_page_' . $this->config->getSettingsPageSlug();

		if ( $screen->id !== $expected_screen_id ) {
			return;
		}

		// Show configuration reminder
		$repository_url = $this->config->getOption( 'repository_url', '' );

		if ( empty( $repository_url ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo '<strong>' . esc_html( $this->config->getPluginName() ) . ':</strong> Please configure your repository URL to enable updates.';
			echo '</p></div>';
		}

		// Show settings saved notice
		if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true' ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo 'Settings saved successfully.';
			echo '</p></div>';
		}
	}

	/**
	 * Get current plugin status for display
	 *
	 * @return array Status information
	 */
	public function getPluginStatus() {
		return array(
			'current_version'       => $this->config->getPluginVersion(),
			'latest_version'        => $this->config->getOption( 'latest_version', '' ),
			'update_available'      => $this->config->getOption( 'update_available', false ),
			'last_checked'          => $this->config->getOption( 'last_checked', 0 ),
			'repository_configured' => ! empty( $this->config->getOption( 'repository_url', '' ) ),
			'last_log'              => $this->updater->getLastLog(),
		);
	}

	/**
	 * Format timestamp for display
	 *
	 * @param int $timestamp Unix timestamp
	 * @return string Formatted date
	 */
	public function formatTimestamp( $timestamp ) {
		if ( empty( $timestamp ) ) {
			return 'Never';
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Get update status message
	 *
	 * @param array $status Plugin status
	 * @return string Status message
	 */
	public function getStatusMessage( $status ) {
		if ( ! $status['repository_configured'] ) {
			return 'Repository not configured';
		}

		if ( empty( $status['latest_version'] ) ) {
			return 'Update check required';
		}

		if ( $status['update_available'] ) {
			return sprintf(
				'Update available: %s → %s',
				$status['current_version'],
				$status['latest_version']
			);
		}

		return 'Plugin is up to date';
	}

	/**
	 * Get status badge CSS class
	 *
	 * @param array $status Plugin status
	 * @return string CSS class
	 */
	public function getStatusBadgeClass( $status ) {
		if ( ! $status['repository_configured'] || empty( $status['latest_version'] ) ) {
			return 'badge-warning';
		}

		if ( $status['update_available'] ) {
			return 'badge-info';
		}

		return 'badge-success';
	}
}
