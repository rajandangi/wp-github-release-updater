# 📦 WordPress GitHub Release Updater Manager

**A self-contained, zero-configuration GitHub Release Updater for WordPress plugins.**

---

## 🎯 What This Is

A lightweight, **highly portable** WordPress plugin that enables manual updates from GitHub releases. This is a **complete updater module** in a single folder that you can copy into any WordPress plugin to enable automatic updates from GitHub releases.

---

## ✨ Key Features

1. **Self-contained** - Everything in one `updater-manager` folder
2. **Zero file editing** - All config via constructor parameters
3. **Zero namespace conflicts** - Uses isolated `WPGitHubReleaseUpdater` namespace
4. **Ultra-simple** - Just ~15 lines of code to integrate
5. **Copy/paste ready** - No complex setup required
6. **Dynamic prefix** - Works with any WordPress installation
7. **Production-ready** - Tested and documented

---

## 🚀 Features

- **Ultra-Simple Integration** - One manager class handles everything
- **Manual Updates Only** - No automatic checks or background processes
- **Public & Private Repositories** - Support for both with Personal Access Tokens
- **Encrypted Token Storage** - Access tokens encrypted using WordPress salts (AES-256-CBC)
- **Simple Interface** - Clean admin panel under Tools menu
- **Version Validation** - Semantic version comparison
- **Minimal Logging** - Single-entry log of recent actions
- **Security First** - Proper permission checks, input sanitization, and encrypted credentials
- **No Dependencies** - Vanilla JavaScript, no jQuery required
- **Highly Portable** - One class, ~15 lines of code
- **Dynamic Prefix** - Automatically uses WordPress table prefix

---

## 📋 Requirements

1. WordPress 6.0 or higher
2. PHP 8.3 or higher
3. ZipArchive PHP extension (for file extraction)
4. `manage_options` capability for users

### Required Plugin Headers

Your plugin **must** include these standard WordPress plugin headers in the main plugin file for the updater to work correctly:

```php
<?php
/**
 * Plugin Name: Your Plugin Name
 * Plugin URI: https://example.com/your-plugin
 * Description: Brief description of your plugin
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * Text Domain: your-plugin-slug
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.3
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */
```

**Minimum Required Headers:**
- **Plugin Name** - Required for display in updater interface
- **Version** - Required for version comparison and update detection

**How Plugin Slug is Determined:**

The plugin slug is **automatically extracted from the main plugin filename** (not from headers):

```
Example File: /wp-content/plugins/my-plugin/my-plugin.php
                                             ↓
Extracted Slug: my-plugin
```

The updater:
1. Takes the filename you pass to `plugin_file` parameter
2. Extracts basename: `my-plugin.php` → `my-plugin`
3. Sanitizes it to create the slug
4. Uses this slug for database option keys: `wp_my-plugin_{option_name}`

**What gets read from headers:**
- ✅ Plugin Name from "Plugin Name" header
- ✅ Current Version from "Version" header
- ✅ Text Domain from "Text Domain" header (optional, defaults to slug)

**What gets extracted from filename:**
- ✅ Plugin Slug (used for database option uniqueness)

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
    <?php
    /**
     * Plugin Name: Your Awesome Plugin
     * Version: 1.0.0
     * Text Domain: your-awesome-plugin
     * ... other headers ...
     */

    // Load the GitHub Updater Manager class.
    require_once __DIR__ . '/updater-manager/class-github-updater-manager.php';

    /**
    * Initialize the GitHub Updater
    *
    * Plugin info extracted automatically from file headers!
    * Plugin slug extracted from filename!
    * Only need to provide menu titles - that's it!
    */
    function wpGitHubReleaseUpdater()
    {
        static $updater = null;

        if ($updater === null) {
            // ULTRA-SIMPLE: Just provide menu titles!
            $updater = new \WPGitHubReleaseUpdater\GitHubUpdaterManager([
                // Required
                'plugin_file' => __FILE__,                    // Your main plugin file
                'menu_title'  => 'GitHub Updater',            // Menu label
                'page_title'  => 'GitHub Release Updater',    // Page title

                // Optional
                // 'menu_parent' => 'tools.php',              // Default: 'tools.php'
                // 'capability'  => 'manage_options',         // Default: 'manage_options'
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

## 🔑 Automatic Uniqueness

The updater automatically ensures complete isolation between plugins using **plugin slug** extracted from your filename:

### What Gets the Plugin Slug?

| Item | Format | Example |
|------|--------|---------|
| **Database Options** | `wp_{slug}_{option}` | `wp_my-plugin_latest_version` |
| **AJAX Actions** | `{slug}_action` | `my-plugin_check` |
| **Asset Handles** | `{slug}-handle` | `my-plugin-admin` |
| **Nonces** | `{slug}_nonce` | `my-plugin_nonce` |

**Plugin File:** `my-plugin.php` → **Slug:** `my-plugin`

### Complete Isolation

```
Plugin A (my-plugin.php):
- Options: wp_my-plugin_*
- AJAX: my-plugin_check
- Assets: my-plugin-admin
- Nonce: my-plugin_nonce

Plugin B (other-plugin.php):
- Options: wp_other-plugin_*
- AJAX: other-plugin_check
- Assets: other-plugin-admin
- Nonce: other-plugin_nonce

✓ No collisions possible!
```

**Why this matters:**
- ✅ Zero configuration needed for uniqueness
- ✅ No `prefix` parameter required
- ✅ Works with multisite installations
- ✅ Prevents data collisions between plugins
- ✅ Plugin slug extracted automatically from directory name

---

## �📁 Folder Structure

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
- **Security:** Tokens are automatically encrypted using WordPress salts (AES-256-CBC)
- Stored tokens cannot be viewed in the database (encrypted at rest)

### 3. Actions
- **Test Repository Access** - Quick connectivity check
- **Check for Updates** - Fetch latest GitHub release and compare versions
- **Update Now** - Download and install using WordPress's native updater

---

## 🔒 Security Features

### Access Token Encryption

The updater implements **AES-256-CBC encryption** for GitHub access tokens:

1. **Industry-Standard Encryption**
   - AES-256-CBC with OpenSSL (guaranteed in PHP 8.3+)
   - Unique initialization vector (IV) per encryption
   - 256-bit key derived from WordPress salts

2. **WordPress Salt-Based Keys**
   - Uses `AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, and `NONCE_KEY`
   - Unique per WordPress installation
   - Cannot be decrypted without access to `wp-config.php`

### Token Protection

- ✅ Encrypted at rest in database
- ✅ Never logged or displayed in plain text
- ✅ Only sent to verified GitHub domains
- ✅ Strict host validation prevents token leaks
- ✅ Authorization header only added during downloads
- ✅ Removed from HTTP filters after update completes

### Best Practices

1. **Use Fine-Grained Personal Access Tokens** (recommended)
   - Create at: https://github.com/settings/tokens?type=beta
   - Grant only `Contents: Read-only` permission
   - Limit to specific repositories

2. **Regular Token Rotation**
   - Rotate tokens every 90 days
   - Revoke unused tokens immediately

3. **Secure Your WordPress Installation**
   - Keep `wp-config.php` above web root
   - Use strong, unique WordPress salts
   - Regular security audits

---

## 📝 Notes

- The updater prefers a zip asset from the latest release
- If no zip asset exists, it falls back to GitHub's zipball
- **Best results**: Attach a zip whose contents match your plugin directory name exactly