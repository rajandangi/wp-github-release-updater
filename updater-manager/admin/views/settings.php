<?php
/**
 * Admin settings page template
 *
 * @package WPGitHubReleaseUpdater
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$status = $this->getPluginStatus();
?>

<div class="wrap">
    <h1><?php echo esc_html($this->config->getPageTitle()); ?></h1>

    <div class="wp-github-updater-container">
        <!-- Status Card -->
        <div class="card">
            <h2 class="title">Plugin Status</h2>
            <table class="wp-github-updater-status-table">
                <tr>
                    <td><strong>Current Version:</strong></td>
                    <td><?php echo esc_html($status['current_version']); ?></td>
                </tr>
                <tr>
                    <td><strong>Latest Version:</strong></td>
                    <td>
                        <?php if (!empty($status['latest_version'])): ?>
                            <?php echo esc_html($status['latest_version']); ?>
                        <?php else: ?>
                            <em>Unknown</em>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Status:</strong></td>
                    <td>
                        <span class="wp-github-updater-status-badge <?php echo esc_attr($this->getStatusBadgeClass($status)); ?>">
                            <?php echo esc_html($this->getStatusMessage($status)); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td><strong>Last Checked:</strong></td>
                    <td><?php echo esc_html($this->formatTimestamp($status['last_checked'])); ?></td>
                </tr>
            </table>

            <!-- Action Buttons -->
            <div class="wp-github-updater-actions">
                <button type="button" id="check-for-updates" class="button button-secondary" <?php echo !$status['repository_configured'] ? 'disabled' : ''; ?>>
                    <span class="button-text">Check for Updates</span>
                    <span class="spinner"></span>
                </button>

                <button type="button" id="update-now" class="button button-primary" <?php echo !$status['update_available'] ? 'disabled' : ''; ?>>
                    <span class="button-text">Update Now</span>
                    <span class="spinner"></span>
                </button>
            </div>

            <!-- Status Messages -->
            <div id="wp-github-updater-messages"></div>
        </div>

        <!-- Settings Form -->
        <div class="card">
            <h2 class="title">Repository Configuration</h2>

            <form method="post" action="options.php" id="wp-github-updater-settings-form">
                <?php
                settings_fields($this->config->getSettingsGroup());
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
                    <?php submit_button('Save Settings', 'secondary', 'submit', false); ?>

                    <button type="button" id="test-repository" class="button button-secondary">
                        <span class="button-text">Test Repository Access</span>
                        <span class="spinner"></span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Activity Log -->
        <?php if (!empty($status['last_log'])): ?>
        <div class="card">
            <h2 class="title">Recent Activity</h2>
            <div class="wp-github-updater-log">
                <div class="log-entry <?php echo $status['last_log']['result'] === 'Success' ? 'success' : 'error'; ?>">
                    <div class="log-timestamp"><?php echo esc_html($status['last_log']['timestamp']); ?></div>
                    <div class="log-action"><?php echo esc_html($status['last_log']['action']); ?></div>
                    <div class="log-result"><?php echo esc_html($status['last_log']['result']); ?></div>
                    <div class="log-message"><?php echo esc_html($status['last_log']['message']); ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<style>
.wp-github-updater-container {
    max-width: 1000px;
}

.wp-github-updater-container .card {
    margin-bottom: 20px;
    padding: 20px;
}

.wp-github-updater-status-table {
    width: 100%;
    margin-bottom: 20px;
}

.wp-github-updater-status-table td {
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f1;
}

.wp-github-updater-status-table td:first-child {
    width: 150px;
}

.wp-github-updater-status-badge {
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.wp-github-updater-status-badge.badge-success {
    background-color: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.wp-github-updater-status-badge.badge-warning {
    background-color: #fff3cd;
    color: #856404;
    border: 1px solid #ffeaa7;
}

.wp-github-updater-status-badge.badge-info {
    background-color: #cce7ff;
    color: #004085;
    border: 1px solid #99d3ff;
}

.wp-github-updater-actions {
    margin-top: 20px;
}

.wp-github-updater-actions .button {
    margin-right: 10px;
    position: relative;
}

.wp-github-updater-actions .button .spinner {
    display: none;
    float: none;
    margin: 0 5px 0 0;
    vertical-align: middle;
}

.wp-github-updater-actions .button.loading .spinner {
    display: inline-block;
    visibility: visible;
}

.wp-github-updater-actions .button.loading .button-text {
    opacity: 0.7;
}

.wp-github-updater-settings-actions {
    margin-top: 20px;
}

.wp-github-updater-settings-actions .button {
    margin-right: 10px;
}

#wp-github-updater-messages {
    margin-top: 15px;
}

#wp-github-updater-messages .notice {
    margin: 5px 0;
    padding: 10px;
}

.wp-github-updater-log {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 4px;
    padding: 15px;
}

.log-entry {
    display: grid;
    grid-template-columns: 150px 80px 80px 1fr;
    gap: 15px;
    align-items: center;
    padding: 10px;
    border-radius: 4px;
}

.log-entry.success {
    background-color: #d4edda;
    border: 1px solid #c3e6cb;
}

.log-entry.error {
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
}

.log-timestamp {
    font-size: 12px;
    color: #6c757d;
}

.log-action {
    font-weight: 600;
}

.log-result {
    font-size: 12px;
    text-transform: uppercase;
    font-weight: 600;
}

.log-message {
    font-size: 13px;
}

</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const checkButton = document.getElementById('check-for-updates');
    const updateButton = document.getElementById('update-now');
    const testButton = document.getElementById('test-repository');
    const messagesContainer = document.getElementById('wp-github-updater-messages');

    function showMessage(message, type = 'info') {
        const notice = document.createElement('div');
        notice.className = `notice notice-${type} is-dismissible`;
        notice.innerHTML = `<p>${message}</p>`;

        messagesContainer.innerHTML = '';
        messagesContainer.appendChild(notice);

        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            if (notice.parentNode) {
                notice.parentNode.removeChild(notice);
            }
        }, 5000);
    }

    function setButtonLoading(button, loading) {
        if (loading) {
            button.classList.add('loading');
            button.disabled = true;
        } else {
            button.classList.remove('loading');
            button.disabled = false;
        }
    }

    function makeAjaxRequest(action, data = {}) {
        return fetch(wpGitHubUpdater.ajaxUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: action,
                nonce: wpGitHubUpdater.nonce,
                ...data
            })
        })
        .then(response => response.json())
        .catch(error => {
            console.error('Ajax error:', error);
            return { success: false, message: wpGitHubUpdater.strings.error };
        });
    }

    // Check for updates
    if (checkButton) {
        checkButton.addEventListener('click', function() {
            setButtonLoading(checkButton, true);

            makeAjaxRequest(wpGitHubUpdater.actions.check)
                .then(result => {
                    setButtonLoading(checkButton, false);

                    if (result.success) {
                        showMessage(result.message, 'success');

                        // Update UI elements
                        const latestVersionCell = document.querySelector('.wp-github-updater-status-table tr:nth-child(2) td:nth-child(2)');
                        const statusBadge = document.querySelector('.wp-github-updater-status-badge');

                        if (latestVersionCell) {
                            latestVersionCell.textContent = result.latest_version;
                        }

                        if (statusBadge) {
                            statusBadge.textContent = result.message;
                            statusBadge.className = 'wp-github-updater-status-badge ' +
                                (result.update_available ? 'badge-info' : 'badge-success');
                        }

                        // Enable/disable update button
                        if (updateButton) {
                            updateButton.disabled = !result.update_available;
                        }

                        // Update last checked time
                        const lastCheckedCell = document.querySelector('.wp-github-updater-status-table tr:nth-child(4) td:nth-child(2)');
                        if (lastCheckedCell) {
                            lastCheckedCell.textContent = 'Just now';
                        }
                    } else {
                        showMessage(result.message, 'error');
                    }
                });
        });
    }

    // Perform update
    if (updateButton) {
        updateButton.addEventListener('click', function() {
            if (!confirm(wpGitHubUpdater.strings.confirm_update)) {
                return;
            }

            setButtonLoading(updateButton, true);

            makeAjaxRequest(wpGitHubUpdater.actions.update)
                .then(result => {
                    setButtonLoading(updateButton, false);

                    if (result.success) {
                        showMessage(result.message, 'success');

                        // Update current version display
                        const currentVersionCell = document.querySelector('.wp-github-updater-status-table tr:nth-child(1) td:nth-child(2)');
                        const latestVersionCell = document.querySelector('.wp-github-updater-status-table tr:nth-child(2) td:nth-child(2)');
                        const statusBadge = document.querySelector('.wp-github-updater-status-badge');

                        if (currentVersionCell && latestVersionCell) {
                            currentVersionCell.textContent = latestVersionCell.textContent;
                        }

                        if (statusBadge) {
                            statusBadge.textContent = 'Plugin is up to date';
                            statusBadge.className = 'wp-github-updater-status-badge badge-success';
                        }

                        // Disable update button
                        updateButton.disabled = true;

                        // Suggest page reload
                        setTimeout(() => {
                            if (confirm('Update completed successfully. Reload the page to see all changes?')) {
                                location.reload();
                            }
                        }, 2000);
                    } else {
                        showMessage(result.message, 'error');

                        if (result.rollback_performed) {
                            showMessage('Plugin has been rolled back to the previous version.', 'warning');
                        }
                    }
                });
        });
    }

    // Test repository access
    if (testButton) {
        testButton.addEventListener('click', function() {
            setButtonLoading(testButton, true);

            const repositoryUrl = document.getElementById('repository_url').value;
            const accessToken = document.getElementById('access_token').value;

            makeAjaxRequest(wpGitHubUpdater.actions.testRepo, {
                repository_url: repositoryUrl,
                access_token: accessToken
            })
                .then(result => {
                    setButtonLoading(testButton, false);

                    if (result.success) {
                        showMessage(result.message, 'success');
                    } else {
                        showMessage(result.message, 'error');
                    }
                });
        });
    }

    // Auto-enable check button when repository is configured
    const repositoryUrlField = document.getElementById('repository_url');
    if (repositoryUrlField && checkButton) {
        repositoryUrlField.addEventListener('input', function() {
            checkButton.disabled = !this.value.trim();
        });
    }
});
</script>