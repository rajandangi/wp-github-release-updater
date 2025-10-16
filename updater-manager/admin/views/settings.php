<?php
/**
 * Admin settings page template
 *
 * @package WPGitHubReleaseUpdater
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status = $this->getPluginStatus();
?>

<div class="wrap">
	<h1><?php echo esc_html( $this->config->getPageTitle() ); ?></h1>

	<div class="wp-github-updater-container">
		<!-- Status Card -->
		<div class="card">
			<h2 class="title">Plugin Status</h2>
			<table class="wp-github-updater-status-table">
				<tr>
					<td><strong>Current Version:</strong></td>
					<td><?php echo esc_html( $status['current_version'] ); ?></td>
				</tr>
				<tr>
					<td><strong>Latest Version:</strong></td>
					<td>
						<?php if ( ! empty( $status['latest_version'] ) ) : ?>
							<?php echo esc_html( $status['latest_version'] ); ?>
						<?php else : ?>
							<em>Unknown</em>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong>Status:</strong></td>
					<td>
						<span class="wp-github-updater-status-badge <?php echo esc_attr( $this->getStatusBadgeClass( $status ) ); ?>">
							<?php echo esc_html( $this->getStatusMessage( $status ) ); ?>
						</span>
					</td>
				</tr>
				<tr>
					<td><strong>Last Checked:</strong></td>
					<td><?php echo esc_html( $this->formatTimestamp( $status['last_checked'] ) ); ?></td>
				</tr>
			</table>

			<!-- Action Buttons -->
			<div class="wp-github-updater-actions">
				<button type="button" id="check-for-updates" class="button button-secondary" <?php echo ! $status['repository_configured'] ? 'disabled' : ''; ?>>
					<span class="button-text">Check for Updates</span>
					<span class="spinner"></span>
				</button>

				<button type="button" id="update-now" class="button button-primary" <?php echo ! $status['update_available'] ? 'disabled' : ''; ?>>
					<span class="button-text">Update Now</span>
					<span class="spinner"></span>
				</button>

				<button type="button" id="clear-cache" class="button button-secondary">
					<span class="button-text">Clear Cache</span>
					<span class="spinner"></span>
				</button>
			</div>

			<!-- Status Messages -->
			<div id="wp-github-updater-messages"></div>

			<!-- Cache Info -->
			<div class="wp-github-updater-cache-info">
				<p><small><em>GitHub API responses are cached for 1 minute to prevent rate limiting.</em></small></p>
			</div>
		</div>

		<!-- Settings Form -->
		<div class="card">
			<h2 class="title">Repository Configuration</h2>

			<form method="post" action="options.php" id="wp-github-updater-settings-form">
				<?php
				settings_fields( $this->config->getSettingsGroup() );
				?>

				<p><?php $this->settingsSectionCallback(); ?></p>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="repository_url">Repository URL</label>
						</th>
						<td>
							<?php $this->repositoryUrlFieldCallback(); ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="access_token">Access Token</label>
						</th>
						<td>
							<?php $this->accessTokenFieldCallback(); ?>
						</td>
					</tr>
				</table>

				<div class="wp-github-updater-settings-actions">
					<?php submit_button( 'Save Settings', 'secondary', 'submit', false ); ?>

					<button type="button" id="test-repository" class="button button-secondary">
						<span class="button-text">Test Repository Access</span>
						<span class="spinner"></span>
					</button>
				</div>
			</form>
		</div>

		<!-- Activity Log -->
		<?php if ( ! empty( $status['last_log'] ) ) : ?>
		<div class="card">
			<h2 class="title">Recent Activity</h2>
			<div class="wp-github-updater-log">
				<div class="log-entry <?php echo $status['last_log']['result'] === 'Success' ? 'success' : 'error'; ?>">
					<div class="log-timestamp"><?php echo esc_html( $status['last_log']['timestamp'] ); ?></div>
					<div class="log-action"><?php echo esc_html( $status['last_log']['action'] ); ?></div>
					<div class="log-result"><?php echo esc_html( $status['last_log']['result'] ); ?></div>
					<div class="log-message"><?php echo esc_html( $status['last_log']['message'] ); ?></div>
				</div>
			</div>
		</div>
		<?php endif; ?>

	</div>
</div>
