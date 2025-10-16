<?php
/**
 * Main GitHub Updater Manager
 *
 * GitHub Release Updater UTILITY for WordPress plugins.
 * Automatically extracts plugin info - you only provide unique prefixes!
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub Updater Manager Class
 *
 * This is the ONLY class you need to instantiate.
 * Plugin info is extracted automatically from your plugin file.
 *
 * Minimal example:
 * new GitHubUpdaterManager([
 *     'plugin_file' => __FILE__,
 *     'prefix' => 'myplugin_gh'  // Just provide a unique prefix!
 * ]);
 */
class GitHubUpdaterManager {

	/**
	 * Configuration instance
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * GitHub API instance
	 *
	 * @var GitHubAPI|null
	 */
	private $github_api;

	/**
	 * Updater instance
	 *
	 * @var Updater|null
	 */
	private $updater;

	/**
	 * Admin instance
	 *
	 * @var Admin|null
	 */
	private $admin;

	/**
	 * Whether the manager has been initialized
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Constructor
	 *
	 * Plugin info is automatically extracted from your plugin file!
	 *
	 * @param array $config_options Configuration options
	 *   Required:
	 *     - plugin_file: string - Your plugin file path (__FILE__)
	 *     - prefix: string - Unique prefix (e.g., 'myplugin_gh')
	 *
	 *   OR instead of 'prefix', use individual prefixes:
	 *     - option_suffix: string (for database options)
	 *     - ajax_prefix: string (for AJAX actions)
	 *     - asset_prefix: string (for JS/CSS handles)
	 *     - nonce_name: string (for security nonce)
	 *
	 *   Optional:
	 *     - menu_parent: string (default: 'tools.php')
	 *     - menu_title: string (default: 'GitHub Updater')
	 *     - page_title: string (default: 'GitHub Release Updater')
	 *     - capability: string (default: 'manage_options')
	 */
	public function __construct( $config_options = array() ) {
		// Extract plugin file
		$plugin_file = $config_options['plugin_file'] ?? null;

		if ( ! $plugin_file ) {
			wp_die( 'GitHubUpdaterManager requires plugin_file parameter' );
		}

		// Create config instance - plugin info extracted automatically!
		$this->config = Config::getInstance( $plugin_file, $config_options );

		// Register hooks
		$this->registerHooks();
	}


	/**
	 * Register WordPress hooks
	 */
	private function registerHooks() {
		// Activation/deactivation hooks must be registered in main plugin file
		// So we provide public methods for those

		// Initialize on plugins_loaded
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Initialize the updater components
	 *
	 * Called automatically on plugins_loaded hook
	 */
	public function init() {
		// Only initialize once
		if ( $this->initialized ) {
			return;
		}

		// Only load in admin area
		if ( ! is_admin() ) {
			return;
		}

		// Load dependencies
		$this->loadDependencies();

		// Initialize components
		$this->initializeComponents();

		$this->initialized = true;

		// Allow others to hook after initialization
		do_action( 'github_updater_initialized', $this );
	}

	/**
	 * Load required files
	 */
	private function loadDependencies() {
		// Get the updater-manager directory (where this class file is located)
		$updater_dir = plugin_dir_path( __DIR__ );

		require_once $updater_dir . 'includes/class-github-api.php';
		require_once $updater_dir . 'includes/class-updater.php';
		require_once $updater_dir . 'admin/class-admin.php';
	}

	/**
	 * Initialize all components
	 */
	private function initializeComponents() {
		// Initialize GitHub API
		$this->github_api = new GitHubAPI( $this->config );

		// Initialize Updater
		$this->updater = new Updater( $this->config, $this->github_api );

		// Initialize Admin interface
		$this->admin = new Admin( $this->config, $this->github_api, $this->updater );
	}

	/**
	 * Activation callback
	 *
	 * Call this from register_activation_hook in your main plugin file
	 */
	public function activate() {
		// Create default options
		$default_options = $this->config->getDefaultOptions();

		foreach ( $default_options as $key => $value ) {
			if ( $this->config->getOption( $key ) === false ) {
				$this->config->addOption( $key, $value );
			}
		}

		// Flush rewrite rules if needed
		flush_rewrite_rules();

		// Allow others to hook after activation
		do_action( 'github_updater_activated', $this );
	}

	/**
	 * Deactivation callback
	 *
	 * Call this from register_deactivation_hook in your main plugin file
	 */
	public function deactivate() {
		// Clean up temporary files if any
		$upload_dir = wp_upload_dir();
		$temp_files = glob( $upload_dir['basedir'] . '/wp-github-updater-temp-*' );

		if ( $temp_files ) {
			foreach ( $temp_files as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}

		// Allow others to hook after deactivation
		do_action( 'github_updater_deactivated', $this );
	}
}
