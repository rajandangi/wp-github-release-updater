/**
 * Admin JavaScript for WP GitHub Release Updater
 * Vanilla JavaScript implementation - no jQuery dependency
 */

document.addEventListener('DOMContentLoaded', function () {
    const checkButton = document.getElementById('check-for-updates');
    const updateButton = document.getElementById('update-now');
    const testButton = document.getElementById('test-repository');
    const messagesContainer = document.getElementById('wp-github-updater-messages');

    /**
     * Show message to user
     * @param {string} message Message text
     * @param {string} type Message type (info, success, warning, error)
     */
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

    /**
     * Set button loading state
     * @param {HTMLElement} button Button element
     * @param {boolean} loading Loading state
     */
    function setButtonLoading(button, loading) {
        if (loading) {
            button.classList.add('loading');
            button.disabled = true;
        } else {
            button.classList.remove('loading');
            button.disabled = false;
        }
    }

    /**
     * Make AJAX request
     * @param {string} action WordPress AJAX action
     * @param {Object} data Additional data to send
     * @returns {Promise} Promise resolving to response data
     */
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

    // Check for updates button handler
    if (checkButton) {
        checkButton.addEventListener('click', function () {
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

    // Update now button handler
    if (updateButton) {
        updateButton.addEventListener('click', function () {
            if (!confirm(wpGitHubUpdater.strings.confirm_update)) {
                return;
            }

            setButtonLoading(updateButton, true);

            makeAjaxRequest(wpGitHubUpdater.actions.update)
                .then(result => {
                    if (result.success && result.redirect_url) {
                        // Redirect immediately to WordPress update screen
                        window.location.href = result.redirect_url;
                    } else if (result.success) {
                        setButtonLoading(updateButton, false);
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
                        setButtonLoading(updateButton, false);
                        showMessage(result.message, 'error');
                    }
                });
        });
    }

    // Test repository access button handler
    if (testButton) {
        testButton.addEventListener('click', function () {
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
        repositoryUrlField.addEventListener('input', function () {
            checkButton.disabled = !this.value.trim();
        });
    }

    // Form validation
    const settingsForm = document.getElementById('wp-github-updater-settings-form');
    if (settingsForm) {
        settingsForm.addEventListener('submit', function (e) {
            const repositoryUrl = document.getElementById('repository_url').value.trim();

            if (!repositoryUrl) {
                e.preventDefault();
                showMessage('Please enter a repository URL before saving.', 'error');
                return false;
            }

            // Basic URL validation
            const urlPattern = /^([a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+|https:\/\/github\.com\/[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+)$/;
            if (!urlPattern.test(repositoryUrl)) {
                e.preventDefault();
                showMessage('Please enter a valid repository URL (owner/repo or GitHub URL).', 'error');
                return false;
            }
        });
    }

    // Handle access token field placeholder behavior
    const accessTokenField = document.getElementById('access_token');
    if (accessTokenField) {
        // Clear placeholder when focusing on a masked field
        accessTokenField.addEventListener('focus', function () {
            if (this.value.match(/^\*+$/)) {
                this.placeholder = 'Enter new token to replace existing one';
            }
        });

        accessTokenField.addEventListener('blur', function () {
            this.placeholder = 'ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
        });
    }
});