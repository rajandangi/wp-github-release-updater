<?php
/**
 * Configuration class for GitHub Release Updater Utility
 *
 * This utility automatically extracts plugin information from the host plugin.
 * Only customization needed: unique prefixes to avoid conflicts!
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuration class
 *
 * Extracts plugin info automatically from the consuming plugin.
 * Only requires unique prefixes for database/AJAX/assets.
 */
class Config {

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Plugin name.
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $plugin_version;

	/**
	 * Plugin file path.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Plugin directory path.
	 *
	 * @var string
	 */
	private $plugin_dir;

	/**
	 * Plugin URL.
	 *
	 * @var string
	 */
	private $plugin_url;

	/**
	 * Plugin basename.
	 *
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * Text domain for translations.
	 *
	 * @var string
	 */
	private $text_domain;

	/**
	 * Updater directory path.
	 *
	 * @var string
	 */
	private $updater_dir;

	/**
	 * Updater URL.
	 *
	 * @var string
	 */
	private $updater_url;

	/**
	 * Option prefix for database options.
	 *
	 * @var string
	 */
	private $option_prefix;

	/**
	 * AJAX prefix for AJAX actions.
	 *
	 * @var string
	 */
	private $ajax_prefix;

	/**
	 * Asset prefix for script/style handles.
	 *
	 * @var string
	 */
	private $asset_prefix;

	/**
	 * Nonce name for security verification.
	 *
	 * @var string
	 */
	private $nonce_name;

	/**
	 * Parent menu slug for admin page.
	 *
	 * @var string
	 */
	private $menu_parent;

	/**
	 * Menu title for admin page.
	 *
	 * @var string
	 */
	private $menu_title;

	/**
	 * Page title for admin page.
	 *
	 * @var string
	 */
	private $page_title;

	/**
	 * Capability required to access settings.
	 *
	 * @var string
	 */
	private $capability;

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	private $settings_page_slug;

	/**
	 * Settings group name.
	 *
	 * @var string
	 */
	private $settings_group;

	/**
	 * AJAX action name for checking updates.
	 *
	 * @var string
	 */
	private $ajax_check_action;

	/**
	 * AJAX action name for performing updates.
	 *
	 * @var string
	 */
	private $ajax_update_action;

	/**
	 * AJAX action name for testing repository connection.
	 *
	 * @var string
	 */
	private $ajax_test_repo_action;

	/**
	 * AJAX action name for clearing cache.
	 *
	 * @var string
	 */
	private $ajax_clear_cache_action;

	/**
	 * Script handle for enqueuing JavaScript.
	 *
	 * @var string
	 */
	private $script_handle;

	/**
	 * Style handle for enqueuing CSS.
	 *
	 * @var string
	 */
	private $style_handle;

	/**
	 * Instance registry - keyed by plugin file path
	 * This prevents config collision when multiple plugins use this updater
	 *
	 * @var array<string, Config>
	 */
	private static $instances = array();

	/**
	 * Get instance for a specific plugin
	 *
	 * @param string $plugin_file Main plugin file path
	 * @param array  $config Configuration options
	 * @return Config
	 */
	public static function getInstance( $plugin_file = null, $config = array() ) {
		if ( ! $plugin_file ) {
			wp_die( 'GitHub Updater: Plugin file is required to get Config instance' );
		}

		// Use realpath to normalize the path for consistent keying
		$key = realpath( $plugin_file );
		if ( false === $key ) {
			$key = $plugin_file; // Fallback if realpath fails
		}

		if ( ! isset( self::$instances[ $key ] ) ) {
			self::$instances[ $key ] = new self( $plugin_file, $config );
		}
		return self::$instances[ $key ];
	}

	/**
	 * Clear instance for a specific plugin (useful for testing)
	 *
	 * @param string $plugin_file Main plugin file path
	 * @return void
	 */
	public static function clearInstance( $plugin_file ) {
		$key = realpath( $plugin_file );
		if ( false === $key ) {
			$key = $plugin_file;
		}
		unset( self::$instances[ $key ] );
	}

	/**
	 * Clear all instances (useful for testing)
	 *
	 * @return void
	 */
	public static function clearAllInstances() {
		self::$instances = array();
	}

	/**
	 * Constructor
	 *
	 * Automatically extracts plugin info from the plugin file headers.
	 * Plugin slug extracted from filename - used for all prefixes automatically!
	 *
	 * @param string $plugin_file Main plugin file path (__FILE__ from consuming plugin)
	 * @param array  $config Configuration options
	 *    Required:
	 *      - menu_title: string - Admin menu title (e.g., 'My Plugin Updates')
	 *      - page_title: string - Admin page title (e.g., 'My Plugin GitHub Updater')
	 *
	 *    Optional:
	 *      - menu_parent: string (default: 'tools.php')
	 *      - capability: string (default: 'manage_options')
	 */
	private function __construct( $plugin_file = null, $config = array() ) {
		if ( ! $plugin_file || ! file_exists( $plugin_file ) ) {
			wp_die( 'GitHub Updater: Invalid plugin file provided' );
		}

		// Validate required configuration
		$this->validateConfig( $config );

		// Set plugin paths
		$this->plugin_file     = $plugin_file;
		$this->plugin_dir      = plugin_dir_path( $plugin_file );
		$this->plugin_url      = plugin_dir_url( $plugin_file );
		$this->plugin_basename = plugin_basename( $plugin_file );

		// Set updater manager paths (where the updater files are located)
		$this->updater_dir = plugin_dir_path( __DIR__ );  // updater-manager/
		$this->updater_url = plugin_dir_url( __DIR__ );   // updater-manager/

		// Extract plugin data from file headers
		$plugin_data          = $this->extractPluginData( $plugin_file );
		$this->plugin_name    = $plugin_data['name'];
		$this->plugin_slug    = $plugin_data['slug'];
		$this->plugin_version = $plugin_data['version'];
		$this->text_domain    = $plugin_data['text_domain'];

		// Get WordPress database table prefix
		global $wpdb;
		$db_prefix = $wpdb->prefix;

		// Use plugin slug for all prefixes (guaranteed unique by WordPress)
		// Plugin slug extracted from filename provides automatic uniqueness
		$option_suffix = $this->plugin_slug . '_';
		$ajax_prefix   = $this->plugin_slug . '_';
		$asset_prefix  = str_replace( '_', '-', $this->plugin_slug ) . '-';
		$nonce_name    = $this->plugin_slug . '_nonce';

		// Set prefixes (wpdb prefix + plugin slug is sufficient for uniqueness)
		$this->option_prefix = $db_prefix . $option_suffix;
		$this->ajax_prefix   = $ajax_prefix;
		$this->asset_prefix  = $asset_prefix;
		$this->nonce_name    = $nonce_name;

		// AJAX action names
		$this->ajax_check_action       = $ajax_prefix . 'check';
		$this->ajax_update_action      = $ajax_prefix . 'update';
		$this->ajax_test_repo_action   = $ajax_prefix . 'test_repo';
		$this->ajax_clear_cache_action = $ajax_prefix . 'clear_cache';

		// Asset handles
		$this->script_handle = $asset_prefix . 'admin';
		$this->style_handle  = $asset_prefix . 'admin';

		// Admin menu settings (menu_title and page_title are required)
		$this->menu_parent = $config['menu_parent'] ?? 'tools.php';
		$this->menu_title  = $config['menu_title'];
		$this->page_title  = $config['page_title'];
		$this->capability  = $config['capability'] ?? 'manage_options';

		// Generate settings page slug and group from plugin slug
		$this->settings_page_slug = str_replace( '_', '-', $this->plugin_slug ) . '-updater-settings';
		$this->settings_group     = $this->plugin_slug . '_updater_settings';
	}

	/**
	 * Validate required configuration
	 *
	 * @param array $config Configuration array
	 */
	private function validateConfig( $config ) {
		// Prefix is now optional - will use plugin slug if not provided

		// Check for required menu settings
		if ( empty( $config['menu_title'] ) ) {
			wp_die( 'GitHub Updater Configuration Error: "menu_title" is required' );
		}

		if ( empty( $config['page_title'] ) ) {
			wp_die( 'GitHub Updater Configuration Error: "page_title" is required' );
		}
	}

	/**
	 * Extract plugin data from plugin file headers
	 *
	 * @param string $plugin_file Plugin file path
	 * @return array Plugin data
	 */
	private function extractPluginData( $plugin_file ) {
		// Get default plugin data
		if ( function_exists( 'get_plugin_data' ) ) {
			$plugin_data = get_plugin_data( $plugin_file, false, false );
		} else {
			// Fallback: parse headers manually
			$plugin_data = $this->parsePluginHeaders( $plugin_file );
		}

		// Extract slug from file name or sanitize plugin name
		$file_name = basename( $plugin_file, '.php' );
		$slug      = sanitize_title( $file_name );

		return array(
			'name'        => $plugin_data['Name'] ?? 'Unknown Plugin',
			'version'     => $plugin_data['Version'] ?? '1.0.0',
			'text_domain' => $plugin_data['TextDomain'] ?? $slug,
			'slug'        => $slug,
		);
	}

	/**
	 * Parse plugin headers manually (fallback)
	 *
	 * @param string $plugin_file Plugin file path
	 * @return array Headers
	 */
	private function parsePluginHeaders( $plugin_file ) {
		$headers = array(
			'Name'       => 'Plugin Name',
			'Version'    => 'Version',
			'TextDomain' => 'Text Domain',
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin file headers, not remote URL
		$file_data   = file_get_contents( $plugin_file, false, null, 0, 8192 );
		$plugin_data = array();

		foreach ( $headers as $key => $value ) {
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $value, '/' ) . ':(.*)$/mi', $file_data, $match ) && $match[1] ) {
				$plugin_data[ $key ] = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
			}
		}

		return $plugin_data;
	}

	/**
	 * Get plugin slug
	 */
	public function getPluginSlug() {
		return $this->plugin_slug;
	}

	/**
	 * Get plugin name
	 */
	public function getPluginName() {
		return $this->plugin_name;
	}

	/**
	 * Get plugin version
	 */
	public function getPluginVersion() {
		return $this->plugin_version;
	}

	/**
	 * Get plugin file
	 */
	public function getPluginFile() {
		return $this->plugin_file;
	}

	/**
	 * Get plugin directory
	 */
	public function getPluginDir() {
		return $this->plugin_dir;
	}

	/**
	 * Get plugin URL
	 */
	public function getPluginUrl() {
		return $this->plugin_url;
	}

	/**
	 * Get updater manager directory
	 */
	public function getUpdaterDir() {
		return $this->updater_dir;
	}

	/**
	 * Get updater manager URL
	 */
	public function getUpdaterUrl() {
		return $this->updater_url;
	}

	/**
	 * Get plugin basename
	 */
	public function getPluginBasename() {
		return $this->plugin_basename;
	}

	/**
	 * Get option prefix
	 */
	public function getOptionPrefix() {
		return $this->option_prefix;
	}

	/**
	 * Get full option name
	 *
	 * @param string $option_name Option name without prefix
	 * @return string Full option name with prefix
	 */
	public function getOptionName( $option_name ) {
		return $this->option_prefix . $option_name;
	}

	/**
	 * Get menu parent
	 */
	public function getMenuParent() {
		return $this->menu_parent;
	}

	/**
	 * Get menu title
	 */
	public function getMenuTitle() {
		return $this->menu_title;
	}

	/**
	 * Get page title
	 */
	public function getPageTitle() {
		return $this->page_title;
	}

	/**
	 * Get capability required
	 */
	public function getCapability() {
		return $this->capability;
	}

	/**
	 * Get settings page slug
	 */
	public function getSettingsPageSlug() {
		return $this->settings_page_slug;
	}

	/**
	 * Get settings group
	 */
	public function getSettingsGroup() {
		return $this->settings_group;
	}

	/**
	 * Get settings section
	 */
	public function getSettingsSection() {
		return $this->plugin_slug . '_main';
	}

	/**
	 * Get AJAX check action
	 */
	public function getAjaxCheckAction() {
		return $this->ajax_check_action;
	}

	/**
	 * Get AJAX update action
	 */
	public function getAjaxUpdateAction() {
		return $this->ajax_update_action;
	}

	/**
	 * Get AJAX test repo action
	 */
	public function getAjaxTestRepoAction() {
		return $this->ajax_test_repo_action;
	}

	/**
	 * Get AJAX clear cache action
	 */
	public function getAjaxClearCacheAction() {
		return $this->ajax_clear_cache_action;
	}

	/**
	 * Get nonce name
	 */
	public function getNonceName() {
		return $this->nonce_name;
	}

	/**
	 * Get text domain
	 */
	public function getTextDomain() {
		return $this->text_domain;
	}

	/**
	 * Get script handle
	 */
	public function getScriptHandle() {
		return $this->script_handle;
	}

	/**
	 * Get style handle
	 */
	public function getStyleHandle() {
		return $this->style_handle;
	}

	/**
	 * Get asset prefix
	 */
	public function getAssetPrefix() {
		return $this->asset_prefix;
	}

	/**
	 * Get all default options
	 *
	 * @return array Default options
	 */
	public function getDefaultOptions() {
		return array(
			'repository_url'   => '',
			'access_token'     => '',
			'last_checked'     => 0,
			'latest_version'   => '',
			'update_available' => false,
			'last_log'         => array(),
		);
	}

	/**
	 * Get option value
	 *
	 * @param string $option_name Option name without prefix
	 * @param mixed  $default_value Default value
	 * @return mixed Option value
	 */
	public function getOption( $option_name, $default_value = false ) {
		return get_option( $this->getOptionName( $option_name ), $default_value );
	}

	/**
	 * Update option value
	 *
	 * @param string $option_name Option name without prefix
	 * @param mixed  $value Option value
	 * @return bool Success status
	 */
	public function updateOption( $option_name, $value ) {
		return update_option( $this->getOptionName( $option_name ), $value );
	}

	/**
	 * Add option value
	 *
	 * @param string $option_name Option name without prefix
	 * @param mixed  $value Option value
	 * @return bool Success status
	 */
	public function addOption( $option_name, $value ) {
		return add_option( $this->getOptionName( $option_name ), $value );
	}

	/**
	 * Delete option value
	 *
	 * @param string $option_name Option name without prefix
	 * @return bool Success status
	 */
	public function deleteOption( $option_name ) {
		return delete_option( $this->getOptionName( $option_name ) );
	}

	/**
	 * Encrypt sensitive data using WordPress salts
	 *
	 * Uses AES-256-CBC encryption with WordPress authentication salts as key material.
	 *
	 * @param string $data Data to encrypt
	 * @return string Encrypted data (base64: encrypted::iv) or empty string on failure
	 */
	public function encrypt( $data ) {
		if ( empty( $data ) ) {
			return '';
		}

		$method    = 'AES-256-CBC';
		$iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( $method ) );
		$encrypted = openssl_encrypt( $data, $method, $this->getEncryptionKey(), 0, $iv );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Used for encryption, not code obfuscation
		return $encrypted ? base64_encode( $encrypted . '::' . base64_encode( $iv ) ) : '';
	}

	/**
	 * Decrypt sensitive data
	 *
	 * @param string $encrypted_data Encrypted data (base64 encoded)
	 * @return string Decrypted data or empty string on failure
	 */
	public function decrypt( $encrypted_data ) {
		if ( empty( $encrypted_data ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Used for decryption, not code obfuscation
		$decoded = base64_decode( $encrypted_data, true );
		if ( ! $decoded || ! strpos( $decoded, '::' ) ) {
			return '';
		}

		list( $encrypted, $iv_encoded ) = explode( '::', $decoded, 2 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Used for decryption, not code obfuscation
		$iv = base64_decode( $iv_encoded, true );

		if ( ! $iv ) {
			return '';
		}

		$decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $this->getEncryptionKey(), 0, $iv );
		return false !== $decrypted ? $decrypted : '';
	}

	/**
	 * Get encryption key from WordPress salts
	 *
	 * @return string Encryption key (32 bytes for AES-256)
	 */
	private function getEncryptionKey() {
		// Use WordPress authentication salts to create a unique key
		// This ensures the key is unique per WordPress installation
		$salt_keys = array(
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
		);

		$key_material = '';
		foreach ( $salt_keys as $salt_key ) {
			if ( defined( $salt_key ) ) {
				$key_material .= constant( $salt_key );
			}
		}

		// If no salts defined, fall back to wp_salt
		if ( empty( $key_material ) ) {
			$key_material = wp_salt( 'auth' );
		}

		// Hash to get consistent 32-byte key for AES-256
		return hash( 'sha256', $key_material, true );
	}

	/**
	 * Save encrypted access token
	 *
	 * @param string $token Access token to encrypt and save
	 * @return bool Success status
	 */
	public function saveAccessToken( $token ) {
		if ( empty( $token ) ) {
			// If token is empty, delete the option
			return $this->deleteOption( 'access_token' );
		}

		$encrypted_token = $this->encrypt( $token );
		return $this->updateOption( 'access_token', $encrypted_token );
	}

	/**
	 * Get decrypted access token
	 *
	 * @return string Decrypted access token
	 */
	public function getAccessToken() {
		$encrypted_token = $this->getOption( 'access_token', '' );

		if ( empty( $encrypted_token ) ) {
			return '';
		}

		return $this->decrypt( $encrypted_token );
	}

	/**
	 * Get cache key prefix for GitHub API caching
	 *
	 * @return string Cache key prefix
	 */
	public function getCachePrefix() {
		return $this->option_prefix . 'github_cache_';
	}

	/**
	 * Get cache duration in seconds
	 * Default: 60 seconds (1 minute)
	 *
	 * @return int Cache duration in seconds
	 */
	public function getCacheDuration() {
		// Fixed 1-minute cache as per requirements
		return 60;
	}
}
