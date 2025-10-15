# 📦 WordPress GitHub Release Updater Manager

**A self-contained, zero-configuration GitHub Release Updater for WordPress plugins.**

---

## 🎯 What This Is

A lightweight, **highly portable** WordPress plugin that enables manual updates from GitHub releases. This is a **complete updater module** in a single folder that you can copy into any WordPress plugin to enable automatic updates from GitHub releases.

---

## ✨ Key Features

1. **Self-contained** - Everything in one `updater-manager` folder
2. **Zero file editing** - All config via constructor parameters
3. **Zero namespace conflicts** - Uses isolated `WPGitHubUpdater` namespace
4. **Ultra-simple** - Just ~15 lines of code to integrate
5. **Copy/paste ready** - No complex setup required
6. **Dynamic prefix** - Works with any WordPress installation
7. **Production-ready** - Tested and documented

---

## 🚀 Features

- **Ultra-Simple Integration** - One manager class handles everything
- **Manual Updates Only** - No automatic checks or background processes
- **Public & Private Repositories** - Support for both with Personal Access Tokens
- **Simple Interface** - Clean admin panel under Tools menu
- **Safe Updates** - Automatic backup and rollback functionality
- **Version Validation** - Semantic version comparison
- **Minimal Logging** - Single-entry log of recent actions
- **Security First** - Proper permission checks and input sanitization
- **No Dependencies** - Vanilla JavaScript, no jQuery required
- **Highly Portable** - One class, ~15 lines of code
- **Dynamic Prefix** - Automatically uses WordPress table prefix

---

## 📋 Requirements

1. WordPress 6.0 or higher
2. PHP 8.3 or higher
3. ZipArchive PHP extension (for file extraction)
4. `manage_options` capability for users

---

## 🔧 Installation

### As a Standalone Plugin

1. Download or clone this plugin to your WordPress plugins directory:
   ```
   wp-content/plugins/wp-github-release-updater/
   ```

2. Activate the plugin through the WordPress admin panel:
   - Go to **Plugins** → **Installed Plugins**
   - Find "WP GitHub Release Updater" and click **Activate**

3. Configure the plugin:
   - Go to **Tools** → **GitHub Updater**
   - Enter your repository information

### Integrate into Your Plugin (Recommended)

1. **Copy the folder** - Copy `updater-manager/` into your plugin directory:
   ```
   your-plugin/
   ├── your-plugin.php
   └── updater-manager/      ← Paste the entire folder here
   ```

2. **Bootstrap the manager** (in your main plugin file):
   ```php
    // Load the GitHub Updater Manager class.
    require_once __DIR__ . '/updater-manager/class-github-updater-manager.php';

    /**
    * Initialize the GitHub Updater
    *
    * Plugin info extracted automatically from file headers!
    * Only need to provide unique prefix and menu titles.
    */
    function wpGitHubReleaseUpdater()
    {
        static $updater = null;

        if ($updater === null) {
            // ULTRA-SIMPLE: Plugin info auto-extracted, just provide unique prefix!
            $updater = new \WPGitHubUpdater\GitHubUpdaterManager([
                // Required
                'plugin_file' => __FILE__,
                'prefix' => 'wp_github_updater',  // Used for DB, AJAX, assets, nonces
                'menu_title' => 'GitHub Updater',
                'page_title' => 'GitHub Release Updater',

                // Optional
                // 'menu_parent' => 'tools.php',  // Default: 'tools.php'
                // 'capability' => 'manage_options',  // Default: 'manage_options'
            ]);
        }

        return $updater;
    }

    // Register activation hook
    register_activation_hook(__FILE__, function() {
        wpGitHubReleaseUpdater()->activate();
    });

    // Register deactivation hook
    register_deactivation_hook(__FILE__, function() {
        wpGitHubReleaseUpdater()->deactivate();
    });

    // Initialize the updater
    wpGitHubReleaseUpdater();
   ```

**Done! 🎉** The admin page appears under Tools.

---

## 📁 Folder Structure

```
updater-manager/
├── class-github-updater-manager.php    ← Single entry point
├── includes/
│   ├── class-config.php                 ← Plugin configuration
│   ├── class-github-api.php             ← GitHub API client
│   └── class-updater.php                ← Update logic
├── admin/
│   ├── class-admin.php                  ← Admin interface
│   ├── css/admin.css                    ← Admin styles
│   ├── js/admin.js                      ← Admin scripts
│   └── views/settings-page.php          ← Admin template
└── README.md                            ← Documentation
```

---

## 🎮 Using the Updater

Go to: **Tools** → **GitHub Updater**, then:

### 1. Repository URL
Accepts either format:
- `yourname/your-plugin`
- `https://github.com/yourname/your-plugin`

### 2. Access Token (Optional)
- Leave empty for public repos
- For private repos, use a GitHub Personal Access Token with `repo` scope

### 3. Actions
- **Test Repository Access** - Quick connectivity check
- **Check for Updates** - Fetch latest GitHub release and compare versions
- **Update Now** - Download and install with backup/rollback

---

## 📝 Notes

- The updater prefers a zip asset from the latest release
- If no zip asset exists, it falls back to GitHub's zipball
- **Best results**: Attach a zip whose contents match your plugin directory name exactly