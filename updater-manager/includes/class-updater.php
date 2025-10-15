<?php
/**
 * Updater class for WP GitHub Release Updater
 *
 * @package WPGitHubReleaseUpdater
 */

namespace WPGitHubUpdater;

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
            'rollback_performed' => false
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

            // Find download asset
            $download_url = $this->findDownloadAsset($release_data);

            if (empty($download_url)) {
                $result['message'] = 'No suitable download asset found in the release.';
                $this->logAction('Download', 'Failure', $result['message']);
                return $result;
            }

            // Create backup before update
            $backup_path = $this->createBackup();

            if (is_wp_error($backup_path)) {
                $result['message'] = 'Failed to create backup: ' . $backup_path->get_error_message();
                $this->logAction('Install', 'Failure', $result['message']);
                return $result;
            }

            // Download new version
            $download_result = $this->downloadUpdate($download_url);

            if (is_wp_error($download_result)) {
                $result['message'] = 'Download failed: ' . $download_result->get_error_message();
                $this->logAction('Download', 'Failure', $result['message']);
                return $result;
            }

            // Install update
            $install_result = $this->installUpdate($download_result['file_path']);

            if (is_wp_error($install_result)) {
                // Rollback on failure
                $rollback_result = $this->rollbackUpdate($backup_path);
                $result['rollback_performed'] = !is_wp_error($rollback_result);

                $result['message'] = 'Installation failed: ' . $install_result->get_error_message();
                if ($result['rollback_performed']) {
                    $result['message'] .= ' Plugin rolled back to previous version.';
                }

                $this->logAction('Install', 'Failure', $result['message']);
                return $result;
            }

            // Clean up
            $this->cleanupTempFiles($download_result['file_path'], $backup_path);

            // Update version info
            $this->config->updateOption('current_version', $latest_version);
            $this->config->updateOption('update_available', false);

            $result['success'] = true;
            $result['message'] = sprintf(
                'Successfully updated to version %s',
                $latest_version
            );

            $this->logAction('Install', 'Success', $result['message']);

        } catch (\Exception $e) {
            $result['message'] = 'Update failed: ' . $e->getMessage();
            $this->logAction('Install', 'Failure', $result['message']);
        }

        return $result;
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
     * Download update file
     *
     * @param string $download_url Download URL
     * @return array|WP_Error Download result with file path
     */
    private function downloadUpdate($download_url)
    {
        $upload_dir = wp_upload_dir();
        $temp_file = $upload_dir['basedir'] . '/wp-github-updater-temp-' . time() . '.zip';

        $download_result = $this->github_api->downloadAsset($download_url, $temp_file);

        if (is_wp_error($download_result)) {
            return $download_result;
        }

        // Validate ZIP file
        if (!$this->isValidZipFile($temp_file)) {
            unlink($temp_file);
            return new \WP_Error('invalid_zip', 'Downloaded file is not a valid ZIP archive.');
        }

        return [
            'file_path' => $temp_file,
            'file_size' => filesize($temp_file)
        ];
    }

    /**
     * Install update from downloaded file
     *
     * @param string $zip_file Path to ZIP file
     * @return bool|WP_Error Installation result
     */
    private function installUpdate($zip_file)
    {
        // Temporarily deactivate plugin
        $was_active = is_plugin_active(plugin_basename($this->plugin_file));

        if ($was_active) {
            deactivate_plugins(plugin_basename($this->plugin_file));
        }

        // Extract ZIP file
        $extract_result = $this->extractZipFile($zip_file);

        if (is_wp_error($extract_result)) {
            // Reactivate plugin if it was active
            if ($was_active) {
                activate_plugin(plugin_basename($this->plugin_file));
            }
            return $extract_result;
        }

        // Reactivate plugin
        if ($was_active) {
            $activation_result = activate_plugin(plugin_basename($this->plugin_file));

            if (is_wp_error($activation_result)) {
                return new \WP_Error(
                    'activation_failed',
                    'Plugin updated but reactivation failed: ' . $activation_result->get_error_message()
                );
            }
        }

        return true;
    }

    /**
     * Create backup of current plugin
     *
     * @return string|WP_Error Backup file path or error
     */
    private function createBackup()
    {
        $upload_dir = wp_upload_dir();
        $backup_file = $upload_dir['basedir'] . '/wp-github-updater-backup-' . time() . '.zip';

        // Create ZIP archive of current plugin
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();

            if ($zip->open($backup_file, \ZipArchive::CREATE) === TRUE) {
                $this->addDirectoryToZip($zip, $this->plugin_dir, '');
                $zip->close();

                return $backup_file;
            }
        }

        return new \WP_Error('backup_failed', 'Could not create backup archive.');
    }

    /**
     * Rollback to backup version
     *
     * @param string $backup_file Backup file path
     * @return bool|WP_Error Rollback result
     */
    private function rollbackUpdate($backup_file)
    {
        if (!file_exists($backup_file)) {
            return new \WP_Error('backup_missing', 'Backup file not found for rollback.');
        }

        // Extract backup
        $extract_result = $this->extractZipFile($backup_file);

        if (is_wp_error($extract_result)) {
            return $extract_result;
        }

        $this->logAction('Rollback', 'Success', 'Plugin rolled back to previous version.');

        return true;
    }

    /**
     * Extract ZIP file to plugin directory
     *
     * @param string $zip_file ZIP file path
     * @return bool|WP_Error Extraction result
     */
    private function extractZipFile($zip_file)
    {
        if (!class_exists('ZipArchive')) {
            return new \WP_Error('zip_not_supported', 'ZipArchive class not available.');
        }

        $zip = new \ZipArchive();

        if ($zip->open($zip_file) !== TRUE) {
            return new \WP_Error('zip_open_failed', 'Could not open ZIP file.');
        }

        // Remove current plugin files (except wp-github-release-updater.php)
        $this->removePluginFiles();

        // Extract ZIP file
        $extract_path = dirname($this->plugin_dir);

        if (!$zip->extractTo($extract_path)) {
            $zip->close();
            return new \WP_Error('zip_extract_failed', 'Could not extract ZIP file.');
        }

        $zip->close();

        return true;
    }

    /**
     * Remove current plugin files for update
     */
    private function removePluginFiles()
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->plugin_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            // Skip the main plugin file to avoid breaking the update process
            if ($file->getPathname() === $this->plugin_file) {
                continue;
            }

            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
    }

    /**
     * Add directory to ZIP archive recursively
     *
     * @param ZipArchive $zip ZIP archive instance
     * @param string $directory Directory path
     * @param string $local_path Local path within ZIP
     */
    private function addDirectoryToZip($zip, $directory, $local_path)
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $file_path = $file->getPathname();
            $relative_path = $local_path . '/' . substr($file_path, strlen($directory) + 1);

            if ($file->isDir()) {
                $zip->addEmptyDir($relative_path);
            } else {
                $zip->addFile($file_path, $relative_path);
            }
        }
    }

    /**
     * Validate ZIP file
     *
     * @param string $file_path ZIP file path
     * @return bool True if valid ZIP file
     */
    private function isValidZipFile($file_path)
    {
        if (!class_exists('ZipArchive')) {
            return true; // Assume valid if we can't check
        }

        $zip = new \ZipArchive();
        $result = $zip->open($file_path, \ZipArchive::CHECKCONS);

        if ($result === TRUE) {
            $zip->close();
            return true;
        }

        return false;
    }

    /**
     * Clean up temporary files
     *
     * @param string ...$files File paths to delete
     */
    private function cleanupTempFiles(...$files)
    {
        foreach ($files as $file) {
            if (!empty($file) && file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Log action with timestamp
     *
     * @param string $action Action name (Check, Download, Install, Rollback)
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