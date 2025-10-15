<?php
/**
 * Updater class for WP GitHub Release Updater
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubReleaseUpdater;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Updater class
 */
class Updater
{
    /**
     * Config instance
     */
    private $config;

    /**
     * GitHub API instance
     */
    private $github_api;

    /**
     * Current plugin version
     */
    private $current_version;

    /**
     * Plugin file path
     */
    private $plugin_file;

    /**
     * Plugin directory path
     */
    private $plugin_dir;

    /**
     * Constructor
     *
     * @param Config $config Configuration instance
     * @param GitHubAPI $github_api GitHub API instance
     */
    public function __construct($config, $github_api)
    {
        $this->config = $config;
        $this->github_api = $github_api;
        $this->current_version = $config->getPluginVersion();
        $this->plugin_file = $config->getPluginFile();
        $this->plugin_dir = $config->getPluginDir();

        // Register auth filter for GitHub downloads (for private repos)
        add_filter('http_request_args', [$this, 'httpAuthForGitHub'], 10, 2);
    }

    /**
     * Check for updates
     *
     * @return array Update check result
     */
    public function checkForUpdates()
    {
        $result = [
            'success' => false,
            'current_version' => $this->current_version,
            'latest_version' => '',
            'update_available' => false,
            'message' => '',
            'release_data' => null
        ];

        try {
            // Get latest release from GitHub
            $release_data = $this->github_api->getLatestRelease();

            if (is_wp_error($release_data)) {
                $result['message'] = $release_data->get_error_message();
                $this->logAction('Check', 'Failure', $result['message']);
                return $result;
            }

            $latest_version = $this->extractVersionFromTag($release_data['tag_name']);

            if (empty($latest_version)) {
                $result['message'] = 'Could not extract version from release tag: ' . $release_data['tag_name'];
                $this->logAction('Check', 'Failure', $result['message']);
                return $result;
            }

            $result['latest_version'] = $latest_version;
            $result['release_data'] = $release_data;
            $result['update_available'] = $this->isUpdateAvailable($this->current_version, $latest_version);
            $result['success'] = true;

            if ($result['update_available']) {
                $result['message'] = sprintf(
                    'Update available: %s → %s',
                    $this->current_version,
                    $latest_version
                );
            } else {
                $result['message'] = 'You have the latest version installed.';
            }

            // Update WordPress options
            $this->config->updateOption('latest_version', $latest_version);
            $this->config->updateOption('update_available', $result['update_available']);
            $this->config->updateOption('last_checked', time());

            $this->logAction('Check', 'Success', $result['message']);

        } catch (\Exception $e) {
            $result['message'] = 'Error checking for updates: ' . $e->getMessage();
            $this->logAction('Check', 'Failure', $result['message']);
        }

        return $result;
    }

    /**
     * Perform plugin update
     *
     * @return array Update result
     */
    public function performUpdate()
    {
        $result = [
            'success' => false,
            'message' => '',
            'redirect_url' => ''
        ];

        try {
            // Check if update is available
            $latest_version = $this->config->getOption('latest_version', '');
            $update_available = $this->config->getOption('update_available', false);

            if (!$update_available || empty($latest_version)) {
                $result['message'] = 'No update available. Please check for updates first.';
                return $result;
            }

            // Get release data
            $release_data = $this->github_api->getLatestRelease();

            if (is_wp_error($release_data)) {
                $result['message'] = 'Failed to fetch release data: ' . $release_data->get_error_message();
                $this->logAction('Download', 'Failure', $result['message']);
                return $result;
            }

            // Resolve package URL (GitHub asset or zipball)
            $package_url = $this->findDownloadAsset($release_data);

            if (empty($package_url)) {
                $result['message'] = 'No suitable download asset found in the release.';
                $this->logAction('Download', 'Failure', $result['message']);
                return $result;
            }

            // Register this as an available update in the core update transient
            $this->registerCoreUpdate($latest_version, $package_url);

            // Build the WordPress native update URL
            $plugin_basename = $this->config->getPluginBasename();
            $update_url = wp_nonce_url(
                self_admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($plugin_basename)),
                'upgrade-plugin_' . $plugin_basename
            );

            $result['success'] = true;
            $result['redirect_url'] = $update_url;
            $result['message'] = 'Redirecting to WordPress update screen...';
            $this->logAction('Update', 'Initiated', 'Redirecting to WordPress update screen for version ' . $latest_version);

        } catch (\Exception $e) {
            $result['message'] = 'Update failed: ' . $e->getMessage();
            $this->logAction('Update', 'Failure', $result['message']);
        }

        return $result;
    }

    /**
     * Register/update the core plugin update transient so WordPress knows
     * about the new version and package URL. This allows the default updater
     * to handle the installation just like an official update.
     *
     * @param string $new_version New version string
     * @param string $package_url Download URL for the zip package
     * @return void
     */
    private function registerCoreUpdate($new_version, $package_url)
    {
        $transient = get_site_transient('update_plugins');

        if (!is_object($transient)) {
            $transient = new \stdClass();
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }

        // Best-effort URL to the repo for details
        $repo_url = $this->config->getOption('repository_url', 'https://github.com');

        $plugin_basename = $this->config->getPluginBasename();

        $transient->response[$plugin_basename] = (object) [
            'slug' => $this->config->getPluginSlug(),
            'plugin' => $plugin_basename,
            'new_version' => $new_version,
            'package' => $package_url,
            'url' => $repo_url,
        ];

        $transient->last_checked = time();
        set_site_transient('update_plugins', $transient);
    }

    /**
     * Add Authorization header for GitHub package downloads if access token is set.
     * Applied temporarily during upgrade.
     *
     * @param array  $args Request args
     * @param string $url  Request URL
     * @return array Modified args
     */
    public function httpAuthForGitHub($args, $url)
    {
        $token = $this->config->getOption('access_token', '');
        if (empty($token)) {
            return $args;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return $args;
        }

        $is_github = (
            stripos($host, 'github.com') !== false ||
            stripos($host, 'codeload.github.com') !== false ||
            stripos($host, 'githubusercontent.com') !== false ||
            stripos($host, 'api.github.com') !== false
        );

        if ($is_github) {
            if (!isset($args['headers'])) {
                $args['headers'] = [];
            }
            $args['headers']['Authorization'] = 'token ' . $token;
            if (!isset($args['headers']['Accept'])) {
                $args['headers']['Accept'] = 'application/octet-stream';
            }
            // Allow larger files
            $args['timeout'] = max($args['timeout'] ?? 30, 300);
        }

        return $args;
    }

    /**
     * Extract version number from GitHub tag
     *
     * @param string $tag Git tag name
     * @return string Version number
     */
    private function extractVersionFromTag($tag)
    {
        // Remove common prefixes like 'v', 'version', 'release'
        $version = preg_replace('/^(v|version|release)[-_]?/i', '', $tag);

        // Validate semantic version format
        if (preg_match('/^\d+\.\d+\.\d+/', $version)) {
            return $version;
        }

        return '';
    }

    /**
     * Compare versions to determine if update is available
     *
     * @param string $current Current version
     * @param string $latest Latest version
     * @return bool True if update is available
     */
    private function isUpdateAvailable($current, $latest)
    {
        return version_compare($latest, $current, '>');
    }

    /**
     * Find suitable download asset from release
     *
     * Priority order:
     * 1. ZIP file matching pattern: {prefix}-v-{version}.zip or {prefix}-{version}.zip
     * 2. Any ZIP file
     * 3. GitHub's zipball_url
     *
     * @param array $release_data GitHub release data
     * @return string Download URL or empty string
     */
    private function findDownloadAsset($release_data)
    {
        if (empty($release_data['assets'])) {
            // No assets, fallback to zipball
            return $this->getFallbackZipball($release_data);
        }

        // Get prefix from config (convert underscores to hyphens for filename matching)
        $prefix = $this->config->getAssetPrefix();
        $prefix = rtrim($prefix, '-'); // Remove trailing hyphen if exists

        // Extract version from tag (e.g., v1.2.3 -> 1.2.3)
        $version = $release_data['tag_name'] ?? '';
        $version = ltrim($version, 'v'); // Remove leading 'v' if exists

        // Build possible filename patterns (in priority order)
        $patterns = [
            $prefix . '-v-' . $version . '.zip',     // prefix-v-1.2.3.zip
            $prefix . '-' . $version . '.zip',       // prefix-1.2.3.zip
            $prefix . '-v' . $version . '.zip',      // prefix-v1.2.3.zip
        ];

        // First pass: Look for files matching our prefix patterns
        foreach ($release_data['assets'] as $asset) {
            if (!$this->isZipFile($asset)) {
                continue;
            }

            $asset_name = strtolower($asset['name']);

            foreach ($patterns as $pattern) {
                if ($asset_name === strtolower($pattern)) {
                    return apply_filters(
                        $this->config->getPluginSlug() . '_download_url',
                        $asset['browser_download_url'],
                        $asset,
                        $release_data
                    );
                }
            }
        }

        // Second pass: Look for any file containing the prefix
        foreach ($release_data['assets'] as $asset) {
            if (!$this->isZipFile($asset)) {
                continue;
            }

            $asset_name = strtolower($asset['name']);

            if (strpos($asset_name, strtolower($prefix)) === 0) {
                return apply_filters(
                    $this->config->getPluginSlug() . '_download_url',
                    $asset['browser_download_url'],
                    $asset,
                    $release_data
                );
            }
        }

        // Third pass: Accept any ZIP file
        foreach ($release_data['assets'] as $asset) {
            if ($this->isZipFile($asset)) {
                return apply_filters(
                    $this->config->getPluginSlug() . '_download_url',
                    $asset['browser_download_url'],
                    $asset,
                    $release_data
                );
            }
        }

        // Fallback to zipball URL
        return $this->getFallbackZipball($release_data);
    }

    /**
     * Check if asset is a ZIP file
     *
     * @param array $asset Asset data
     * @return bool
     */
    private function isZipFile($asset)
    {
        return $asset['content_type'] === 'application/zip' ||
               pathinfo($asset['name'], PATHINFO_EXTENSION) === 'zip';
    }

    /**
     * Get fallback zipball URL
     *
     * @param array $release_data Release data
     * @return string
     */
    private function getFallbackZipball($release_data)
    {
        if (!empty($release_data['zipball_url'])) {
            return apply_filters(
                $this->config->getPluginSlug() . '_download_url',
                $release_data['zipball_url'],
                null,
                $release_data
            );
        }

        return '';
    }

    /**
     * Log action with timestamp
     *
     * @param string $action Action name (Check, Download, Install)
     * @param string $result Result (Success, Failure)
     * @param string $message Message
     */
    private function logAction($action, $result, $message)
    {
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'action' => $action,
            'result' => $result,
            'message' => $message
        ];

        // Store only the most recent log entry
        $this->config->updateOption('last_log', $log_entry);
    }

    /**
     * Get the last log entry
     *
     * @return array Log entry
     */
    public function getLastLog()
    {
        return $this->config->getOption('last_log', []);
    }
}