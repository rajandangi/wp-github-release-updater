# Edge Case Issues in GitHub Release Updater

## Critical Issues Found

### 1. **Race Condition: Stale Update Data**
**Location:** `class-updater.php` - `performUpdate()` method (line 127-131)

**Issue:**
```php
// Check if update is available
$latest_version = $this->config->getOption('latest_version', '');
$update_available = $this->config->getOption('update_available', false);

if (!$update_available || empty($latest_version)) {
    $result['message'] = 'No update available. Please check for updates first.';
    return $result;
}
```

**Problem:** The method relies on cached data (`latest_version` and `update_available`) from a previous `checkForUpdates()` call, but then makes a fresh API call to get release data:
```php
// Get release data
$release_data = $this->github_api->getLatestRelease();
```

**Edge Cases:**
1. **Version Changed Between Checks:** If a new release is published AFTER `checkForUpdates()` but BEFORE `performUpdate()`, the user could download a different version than what was displayed
2. **Release Deleted:** If the release shown is deleted before update, user gets confusing error
3. **Pre-release Promotion:** If a pre-release is promoted to stable between calls, version mismatch occurs

**Recommendation:** Either:
- Show a bold warning "Release data may have changed, please re-check for updates before updating"

---

### 2. **Version Comparison Issue: Non-Semantic Versions**
**Location:** `class-updater.php` - `extractVersionFromTag()` (line 285-294)

**Issue:**
```php
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
```

**Problems:**

#### 2.1 Incomplete Semantic Version Validation
The regex `'/^\d+\.\d+\.\d+/'` only validates that the tag **starts** with a semantic version pattern, but doesn't ensure the entire string is valid.

**Edge Cases:**
- ✅ Matches: `v1.2.3-alpha` → Returns `1.2.3-alpha`
- ✅ Matches: `v1.2.3.beta` → Returns `1.2.3.beta`
- ✅ Matches: `v1.2.3garbage` → Returns `1.2.3garbage`
- ❌ Fails: `v1.2` → Returns empty (valid but less common)
- ❌ Fails: `v2.0` → Returns empty (valid but less common)

**Issue:** Tags like `1.2.3-beta.1` or `1.2.3+build.123` are valid semantic versions but may cause issues with WordPress's `version_compare()`.

#### 2.2 Pre-release/Build Metadata Handling
The code doesn't strip or handle pre-release identifiers properly, which can cause unexpected behavior:

```php
// Current version: 1.0.5
// GitHub tag: v1.0.6-beta.1
// Extracted: 1.0.6-beta.1
version_compare('1.0.6-beta.1', '1.0.5', '>') // TRUE - WRONG!
```

PHP's `version_compare()` treats `-beta` alphabetically, so `1.0.6-beta.1` > `1.0.5` returns `true`, but I don't want users auto-updating to beta releases!

---

### 3. **Transient Pollution: No Cleanup**
**Location:** `class-updater.php` - `registerCoreUpdate()` (line 205-230)

**Issue:**
```php
private function registerCoreUpdate($new_version, $package_url)
{
    $transient = get_site_transient('update_plugins');

    // ... manipulate transient ...

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
```

**Problems:**

#### 3.1 Transient Never Cleared
Once an update is registered in the transient, it persists until WordPress naturally refreshes it (usually 12 hours). This causes:

**Edge Cases:**
1. **Failed Update Retry:** If update fails, transient still shows update available → users see confusing "update available" badge
2. **Successful Update Notification:** After successful update, WordPress may still show "1 update available" until transient expires
3. **Manual Version Downgrade:** If user manually downgrades, transient still points to newer version

#### 3.2 No Transient Verification
The code doesn't verify if the transient data is still valid:
- What if the package URL expires?
- What if the release is deleted?
- What if network conditions changed?

Recommendation: Clear or refresh the transient after checking for updates or after an update attempt succeeds/fails.

---

### 4. **Download Asset Matching Logic Flaw**
**Location:** `class-updater.php` - `findDownloadAsset()` (line 310-342)

**Issue:**
```php
private function findDownloadAsset($release_data)
{
    if (empty($release_data['assets'])) {
        // No assets, fallback to zipball
        return $this->getFallbackZipball($release_data);
    }

    // Get prefix from config
    $prefix = $this->config->getAssetPrefix();
    $prefix = rtrim($prefix, '-'); // Remove trailing hyphen if exists

    // Expected filename: prefix.zip
    $expected_filename = $prefix . '.zip';

    // Look for the exact file matching our pattern
    foreach ($release_data['assets'] as $asset) {
        if (!$this->isZipFile($asset)) {
            continue;
        }

        $asset_name = strtolower($asset['name']);

        if ($asset_name === strtolower($expected_filename)) {
            return apply_filters(...);
        }
    }

    // Fallback to zipball URL
    return $this->getFallbackZipball($release_data);
}
```

**Problems:**

#### 4.1 Case Sensitivity Issue
Using `strtolower()` for comparison is good, but config might be inconsistent:
```php
// Config: asset_prefix = 'MyPlugin'
// Expected: 'myplugin.zip'
// Uploaded: 'MyPlugin.zip' ✅ Matches (due to strtolower)
// Uploaded: 'MYPLUGIN.ZIP' ✅ Matches
// Uploaded: 'my-plugin.zip' ❌ Doesn't match
```
**Recommendation:** Use a more flexible matching approach, e.g., regex or allow underscores/hyphens.

#### 4.2 No Validation of Zipball URL
Falls back to `zipball_url` without checking if it exists or is accessible:
```php
private function getFallbackZipball($release_data)
{
    if (!empty($release_data['zipball_url'])) {
        return apply_filters(...);
    }
    return '';
}
```

**Edge Case:** If zipball_url is an empty string but the key exists, this returns '', causing silent failure.
**Recommendation:** Never fallback to zipball if no assets found. Instead, return error.

#### 4.3 Multiple Zip Files in Release
If a release has multiple ZIP files:
```
- my-plugin.zip
- my-plugin-source.zip
- my-plugin-build.zip
```

The code only matches exact prefix. If the asset_prefix doesn't match, it falls back to zipball (which may include source code, test files, etc., causing bloat).

**Recommendation:** Never fallback to zipball if assets exist. Instead, return error if no matching asset found.

---

### 5. **No Rollback Mechanism**
**Location:** `class-updater.php` - `performUpdate()` (line 127-193)

**Issue:** The updater triggers WordPress's native update process but provides no rollback option.

**Edge Cases:**
1. **Broken Release:** If the new version is buggy/broken, no automated rollback
2. **Incompatible Dependencies:** New version might require PHP 8.1, but site runs PHP 7.4 → fatal error
3. **Database Migration Issues:** If new version has DB schema changes that fail

**Current Flow:**
```
checkForUpdates() → performUpdate() → WordPress native updater → DONE
                                                                    ↑
                                                            No rollback if fails
```

**Recommendation:** Store current version package URL in transient before updating, provide "Rollback" button if update fails.

---

---

### 7. **Concurrent Update Attempts**
**Location:** `class-updater.php` - `performUpdate()`

**Issue:** No locking mechanism to prevent multiple admins from triggering updates simultaneously.

**Edge Cases:**
1. **Two admins click "Update Now" at the same time**
   - Both get redirected to WordPress update screen
   - WordPress might handle this gracefully, or might cause corruption

2. **Auto-update + Manual Update Collision**
   - If WordPress has auto-updates enabled and admin manually updates

**No Lock/Flag Check:**
```php
// Missing:
if (get_transient('plugin_update_in_progress')) {
    return ['success' => false, 'message' => 'Update already in progress'];
}
set_transient('plugin_update_in_progress', true, 300);
```

---

### 8. **Authentication Edge Cases**
**Location:** `class-updater.php` - `httpAuthForGitHub()` (line 240-275)

**Issue:**
```php
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
        // ...
    }
}
```

**Problems:**

#### 8.1 Token Validation
No validation that the token is actually valid before using it. A malformed token causes 401 errors.

#### 8.2 Token Expiration
GitHub personal access tokens can expire. No check for:
- Token expiration date
- Token scope/permissions
- Token revocation

#### 8.3 Filter Applied Globally
This filter is registered in constructor and applies to ALL HTTP requests site-wide while the plugin is active:
```php
add_filter('http_request_args', [$this, 'httpAuthForGitHub'], 10, 2);
```

**Edge Case:** If another plugin makes requests to a domain like `my-github.com` or `github.company.com`, this filter applies the token, potentially leaking credentials!

**Recommendation:** Only apply filter during actual update process, then remove it.

---

### 9. **Missing Version Format Edge Cases**
**Location:** `class-updater.php` - Version extraction and comparison

**Edge Cases Not Handled:**

#### 9.1 Version with Build Numbers
```
Tag: v1.2.3+20230101
Extracted: 1.2.3+20230101
version_compare('1.2.3+20230101', '1.2.3', '>') → FALSE (unexpected!)
```

#### 9.2 Four-Part Versions
```
Tag: v1.2.3.4
Current regex: /^\d+\.\d+\.\d+/
Extracted: 1.2.3.4 ✅
But: version_compare('1.2.3.4', '1.2.3', '>') → TRUE ✅
```

#### 9.3 Leading Zeros
```
Tag: v1.02.3
Extracted: 1.02.3
version_compare('1.02.3', '1.2.3', '>') → FALSE (1.02.3 < 1.2.3)
```

---

### 10. **Config Singleton Issue**
**Location:** `class-config.php` - `getInstance()` (line 75-82)

**Issue:**
```php
public static function getInstance($plugin_file = null, $config = [])
{
    if (self::$instance === null) {
        self::$instance = new self($plugin_file, $config);
    }
    return self::$instance;
}
```

**Problem:** Once initialized, subsequent calls with different config are ignored.

**Edge Cases:**
1. **Multiple Plugins Using This Updater:**
   If two different plugins both use this updater library:
   ```php
   // Plugin A initializes first
   Config::getInstance(__FILE__, ['prefix' => 'pluginA']);

   // Plugin B tries to initialize
   Config::getInstance(__FILE__, ['prefix' => 'pluginB']); // Uses Plugin A's config!
   ```

2. **Unit Testing:** Cannot reset singleton between tests

**Impact:** This is a **critical namespace collision** issue since this updater is meant to be reusable across multiple plugins.
**Recommendation:** Avoid singleton pattern or use a registry of instances keyed by plugin file.

---

## Recommendations Summary

### High Priority
1. **Fix Race Condition:** Store release data with version check, validate before update
2. **Handle Pre-releases:** Filter out pre-release versions or make it configurable
3. **Clear Transient:** Clean up after successful/failed updates
4. **Fix Singleton:** Use plugin-specific instances instead of global singleton
5. **Token Scope:** Only apply auth filter during actual download, not globally

### Medium Priority
7. **Version Validation:** Improve regex to handle edge cases
8. **Asset Matching:** Support multiple naming patterns or configurable regex
9. **Concurrent Update Lock:** Add transient-based locking

### Low Priority
10. **Rollback Support:** Store previous version package URL for rollback
11. **Token Validation:** Verify token before using

---

## Test Cases to Add

```php
// Test race condition
1. Check for update (version 1.0.5)
2. Publish new release (version 1.0.6) on GitHub
3. Click "Update Now" without re-checking
4. Verify: Should either update to 1.0.6 or show error

// Test pre-release filtering
1. Current version: 1.0.5
2. Latest release: 1.0.6-beta.1
3. Check for updates
4. Expected: "No update available" (or configurable behavior)

// Test version edge cases
1. Tags: v1.2, v1.2.3, v1.2.3.4, v1.2.3-beta, v1.2.3+build
2. Verify all extract correctly and compare properly

// Test concurrent updates
1. Two admins click "Update Now" simultaneously
2. Verify: Only one proceeds, other gets "update in progress" message

// Test token leak
1. Set access token
2. Make request to non-GitHub domain containing "github" in name
3. Verify: Token not leaked in headers
```

---