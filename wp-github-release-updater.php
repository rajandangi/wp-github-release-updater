<?php
/**
 * Plugin Name: WP GitHub Release Updater
 * Plugin URI: https://github.com/rajandangi/wp-github-release-updater.git
 * Description: A lightweight WordPress plugin that enables automatic updates from GitHub releases instead of WordPress.org. Manual checks and updates only.
 * Version: 1.0.5
 * Requires at least: 6.0
 * Requires PHP: 8.3
 * Author: Rajan Dangi
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-github-release-updater
 * Network: false
 *
 * @package WPGitHubReleaseUpdater
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the GitHub Updater Manager class.
require_once __DIR__ . '/updater-manager/class-github-updater-manager.php';

/**
 * Initialize the GitHub Updater
 *
 * Plugin info extracted automatically from file headers!
 * Only need to provide unique prefix and menu titles.
 */
function wpGitHubReleaseUpdater() {
	static $updater = null;

	if ( null === $updater ) {
		// ULTRA-SIMPLE: Plugin info auto-extracted, just provide unique prefix!
		$updater = new \WPGitHubReleaseUpdater\GitHubUpdaterManager(
			array(
				// Required
				'plugin_file' => __FILE__,
				'prefix'      => 'wp_github_release_updater',  // Used for DB, AJAX, assets, nonces
				'menu_title'  => 'GitHub Updater',
				'page_title'  => 'GitHub Release Updater',

			// Optional
			// 'menu_parent' => 'tools.php',  // Default: 'tools.php'
			// 'capability' => 'manage_options',  // Default: 'manage_options'
			)
		);
	}

	return $updater;
}

// Register activation hook
register_activation_hook(
	__FILE__,
	function () {
		wpGitHubReleaseUpdater()->activate();
	}
);

// Register deactivation hook
register_deactivation_hook(
	__FILE__,
	function () {
		wpGitHubReleaseUpdater()->deactivate();
	}
);

// Initialize the updater
wpGitHubReleaseUpdater();
