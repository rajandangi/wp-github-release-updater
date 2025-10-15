<?php
/**
 * Configuration class for GitHub Release Updater Utility
 *
 * This utility automatically extracts plugin information from the host plugin.
 * Only customization needed: unique prefixes to avoid conflicts!
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubUpdater;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Configuration class
 *
 * Extracts plugin info automatically from the consuming plugin.
 * Only requires unique prefixes for database/AJAX/assets.
 */
class Config
{
    /**
     * Plugin information (auto-extracted from plugin file)
     */
    private $plugin_slug;
    private $plugin_name;
    private $plugin_version;
    private $plugin_file;
    private $plugin_dir;
    private $plugin_url;
    private $plugin_basename;
    private $text_domain;

    /**
     * Updater manager paths (for loading updater assets/views)
     */
    private $updater_dir;
    private $updater_url;

    /**
     * Utility configuration (customizable prefixes)
     */
    private $option_prefix;
    private $ajax_prefix;
    private $asset_prefix;
    private $nonce_name;

    /**
     * Admin settings (customizable)
     */
    private $menu_parent;
    private $menu_title;
    private $page_title;
    private $capability;
    private $settings_page_slug;
    private $settings_group;

    /**
     * AJAX action names (auto-generated from ajax_prefix)
     */
    private $ajax_check_action;
    private $ajax_update_action;
    private $ajax_test_repo_action;

    /**
     * Asset handles (auto-generated from asset_prefix)
     */
    private $script_handle;
    private $style_handle;

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Get instance
     *
     * @param string $plugin_file Main plugin file path
     * @param array $config Configuration options
     */
    public static function getInstance($plugin_file = null, $config = [])
    {
        if (self::$instance === null) {
            self::$instance = new self($plugin_file, $config);
        }
        return self::$instance;
    }

    /**
     * Constructor
     *
     * Automatically extracts plugin info from the plugin file headers.
     * Requires customization for unique prefix and menu settings!
     *
     * @param string $plugin_file Main plugin file path (__FILE__ from consuming plugin)
     * @param array $config Utility configuration options (ALL REQUIRED)
     *   Required:
     *     - prefix: string - Unique prefix for everything (e.g., 'myplugin_gh')
     *     - menu_title: string - Admin menu title (e.g., 'My Plugin Updates')
     *     - page_title: string - Admin page title (e.g., 'My Plugin GitHub Updater')
     *
     *   Optional admin customization:
     *     - menu_parent: string (default: 'tools.php')
     *     - capability: string (default: 'manage_options')
     */
    private function __construct($plugin_file = null, $config = [])
    {
        if (!$plugin_file || !file_exists($plugin_file)) {
            wp_die('GitHub Updater: Invalid plugin file provided');
        }

        // Validate required configuration
        $this->validateConfig($config);

        // Set plugin paths
        $this->plugin_file = $plugin_file;
        $this->plugin_dir = plugin_dir_path($plugin_file);
        $this->plugin_url = plugin_dir_url($plugin_file);
        $this->plugin_basename = plugin_basename($plugin_file);

        // Set updater manager paths (where the updater files are located)
        $this->updater_dir = plugin_dir_path(dirname(__FILE__));  // updater-manager/
        $this->updater_url = plugin_dir_url(dirname(__FILE__));   // updater-manager/

        // Extract plugin data from file headers
        $plugin_data = $this->extractPluginData($plugin_file);
        $this->plugin_name = $plugin_data['name'];
        $this->plugin_slug = $plugin_data['slug'];
        $this->plugin_version = $plugin_data['version'];
        $this->text_domain = $plugin_data['text_domain'];

        // Get WordPress database table prefix
        global $wpdb;
        $db_prefix = $wpdb->prefix;

        // Generate all prefixes from the single base prefix
        $base_prefix = $config['prefix'];
        $option_suffix = $base_prefix . '_';
        $ajax_prefix = $base_prefix . '_';
        $asset_prefix = str_replace('_', '-', $base_prefix) . '-';
        $nonce_name = $base_prefix . '_nonce';

        // Set prefixes
        $this->option_prefix = $db_prefix . $option_suffix;
        $this->ajax_prefix = $ajax_prefix;
        $this->asset_prefix = $asset_prefix;
        $this->nonce_name = $nonce_name;

        // AJAX action names
        $this->ajax_check_action = $ajax_prefix . 'check';
        $this->ajax_update_action = $ajax_prefix . 'update';
        $this->ajax_test_repo_action = $ajax_prefix . 'test_repo';

        // Asset handles
        $this->script_handle = $asset_prefix . 'admin';
        $this->style_handle = $asset_prefix . 'admin';

        // Admin menu settings (menu_title and page_title are required)
        $this->menu_parent = $config['menu_parent'] ?? 'tools.php';
        $this->menu_title = $config['menu_title'];
        $this->page_title = $config['page_title'];
        $this->capability = $config['capability'] ?? 'manage_options';

        // Generate settings page slug and group from prefix (not plugin slug)
        $this->settings_page_slug = str_replace('_', '-', $base_prefix) . '-settings';
        $this->settings_group = $base_prefix . '_settings';
    }

    /**
     * Validate required configuration
     *
     * @param array $config Configuration array
     */
    private function validateConfig($config)
    {
        // Check for required prefix
        if (empty($config['prefix'])) {
            wp_die('GitHub Updater Configuration Error: "prefix" is required (e.g., "myplugin_gh")');
        }

        // Check for required menu settings
        if (empty($config['menu_title'])) {
            wp_die('GitHub Updater Configuration Error: "menu_title" is required');
        }

        if (empty($config['page_title'])) {
            wp_die('GitHub Updater Configuration Error: "page_title" is required');
        }
    }

    /**
     * Extract plugin data from plugin file headers
     *
     * @param string $plugin_file Plugin file path
     * @return array Plugin data
     */
    private function extractPluginData($plugin_file)
    {
        // Get default plugin data
        if (function_exists('get_plugin_data')) {
            $plugin_data = get_plugin_data($plugin_file, false, false);
        } else {
            // Fallback: parse headers manually
            $plugin_data = $this->parsePluginHeaders($plugin_file);
        }

        // Extract slug from file name or sanitize plugin name
        $file_name = basename($plugin_file, '.php');
        $slug = sanitize_title($file_name);

        return [
            'name' => $plugin_data['Name'] ?? 'Unknown Plugin',
            'version' => $plugin_data['Version'] ?? '1.0.0',
            'text_domain' => $plugin_data['TextDomain'] ?? $slug,
            'slug' => $slug,
        ];
    }

    /**
     * Parse plugin headers manually (fallback)
     *
     * @param string $plugin_file Plugin file path
     * @return array Headers
     */
    private function parsePluginHeaders($plugin_file)
    {
        $headers = [
            'Name' => 'Plugin Name',
            'Version' => 'Version',
            'TextDomain' => 'Text Domain',
        ];

        $file_data = file_get_contents($plugin_file, false, null, 0, 8192);
        $plugin_data = [];

        foreach ($headers as $key => $value) {
            if (preg_match('/^[ \t\/*#@]*' . preg_quote($value, '/') . ':(.*)$/mi', $file_data, $match) && $match[1]) {
                $plugin_data[$key] = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]));
            }
        }

        return $plugin_data;
    }

    /**
     * Initialize with plugin file
     *
     * @param string $plugin_file Main plugin file path
     */
    public function init($plugin_file)
    {
        $this->plugin_file = $plugin_file;
        $this->plugin_dir = plugin_dir_path($plugin_file);
        $this->plugin_url = plugin_dir_url($plugin_file);
        $this->plugin_basename = plugin_basename($plugin_file);
    }

    /**
     * Get plugin slug
     */
    public function getPluginSlug()
    {
        return $this->plugin_slug;
    }

    /**
     * Get plugin name
     */
    public function getPluginName()
    {
        return $this->plugin_name;
    }

    /**
     * Get plugin version
     */
    public function getPluginVersion()
    {
        return $this->plugin_version;
    }

    /**
     * Get plugin file
     */
    public function getPluginFile()
    {
        return $this->plugin_file;
    }

    /**
     * Get plugin directory
     */
    public function getPluginDir()
    {
        return $this->plugin_dir;
    }

    /**
     * Get plugin URL
     */
    public function getPluginUrl()
    {
        return $this->plugin_url;
    }

    /**
     * Get updater manager directory
     */
    public function getUpdaterDir()
    {
        return $this->updater_dir;
    }

    /**
     * Get updater manager URL
     */
    public function getUpdaterUrl()
    {
        return $this->updater_url;
    }

    /**
     * Get plugin basename
     */
    public function getPluginBasename()
    {
        return $this->plugin_basename;
    }

    /**
     * Get option prefix
     */
    public function getOptionPrefix()
    {
        return $this->option_prefix;
    }

    /**
     * Get full option name
     *
     * @param string $option_name Option name without prefix
     * @return string Full option name with prefix
     */
    public function getOptionName($option_name)
    {
        return $this->option_prefix . $option_name;
    }

    /**
     * Get menu parent
     */
    public function getMenuParent()
    {
        return $this->menu_parent;
    }

    /**
     * Get menu title
     */
    public function getMenuTitle()
    {
        return $this->menu_title;
    }

    /**
     * Get page title
     */
    public function getPageTitle()
    {
        return $this->page_title;
    }

    /**
     * Get capability required
     */
    public function getCapability()
    {
        return $this->capability;
    }

    /**
     * Get settings page slug
     */
    public function getSettingsPageSlug()
    {
        return $this->settings_page_slug;
    }

    /**
     * Get settings group
     */
    public function getSettingsGroup()
    {
        return $this->settings_group;
    }

    /**
     * Get settings section
     */
    public function getSettingsSection()
    {
        return $this->plugin_slug . '_main';
    }

    /**
     * Get AJAX check action
     */
    public function getAjaxCheckAction()
    {
        return $this->ajax_check_action;
    }

    /**
     * Get AJAX update action
     */
    public function getAjaxUpdateAction()
    {
        return $this->ajax_update_action;
    }

    /**
     * Get AJAX test repo action
     */
    public function getAjaxTestRepoAction()
    {
        return $this->ajax_test_repo_action;
    }

    /**
     * Get nonce name
     */
    public function getNonceName()
    {
        return $this->nonce_name;
    }

    /**
     * Get text domain
     */
    public function getTextDomain()
    {
        return $this->text_domain;
    }

    /**
     * Get script handle
     */
    public function getScriptHandle()
    {
        return $this->script_handle;
    }

    /**
     * Get style handle
     */
    public function getStyleHandle()
    {
        return $this->style_handle;
    }

    /**
     * Get asset prefix
     */
    public function getAssetPrefix()
    {
        return $this->asset_prefix;
    }

    /**
     * Get all default options
     *
     * @return array Default options
     */
    public function getDefaultOptions()
    {
        return [
            'repository_url' => '',
            'access_token' => '',
            'last_checked' => 0,
            'current_version' => $this->plugin_version,
            'latest_version' => '',
            'update_available' => false,
            'last_log' => []
        ];
    }

    /**
     * Get option value
     *
     * @param string $option_name Option name without prefix
     * @param mixed $default Default value
     * @return mixed Option value
     */
    public function getOption($option_name, $default = false)
    {
        return get_option($this->getOptionName($option_name), $default);
    }

    /**
     * Update option value
     *
     * @param string $option_name Option name without prefix
     * @param mixed $value Option value
     * @return bool Success status
     */
    public function updateOption($option_name, $value)
    {
        return update_option($this->getOptionName($option_name), $value);
    }

    /**
     * Add option value
     *
     * @param string $option_name Option name without prefix
     * @param mixed $value Option value
     * @return bool Success status
     */
    public function addOption($option_name, $value)
    {
        return add_option($this->getOptionName($option_name), $value);
    }

    /**
     * Delete option value
     *
     * @param string $option_name Option name without prefix
     * @return bool Success status
     */
    public function deleteOption($option_name)
    {
        return delete_option($this->getOptionName($option_name));
    }
}
