# Corally WordPress Manager

An installable WordPress plugin that gives authenticated AI workers (including Claude) the existing Corally REST bridge. Version 1.5.0 replaces the single-file 1.4.0 MU plugin and adds updates from public GitHub Releases in [Kanava/corally_wordpress_manager](https://github.com/Kanava/corally_wordpress_manager).

Requires WordPress 6.0+ and PHP 7.4+. No GitHub token, vendor account, API key, or worker-specific SDK is required.

## Install or migrate from the MU plugin

1. Download **corally-wordpress-manager.zip** from a release. Use the attached plugin ZIP, not GitHub's automatic source archive.
2. If migrating, remove `wp-content/mu-plugins/corally-wp-developer.php` (or the renamed legacy copy/loader). Keep a backup outside the web root. Do not replace that MU file with the new bootstrap: the new plugin requires its `includes` directory.
3. In WordPress, open **Plugins → Add New → Upload Plugin**, upload the ZIP, and activate **Corally WordPress Manager**. The installed path is `wp-content/plugins/corally-wordpress-manager/corally-wp-developer.php`.
4. Open **Tools → Corally Manager** for connection details and update status. On multisite, a network status page is also available under **Network Admin → Settings → Corally Manager**.
5. Optionally enable **auto-updates** for this plugin on the Plugins screen.

The plugin blocks activation while the old MU file is present and avoids duplicate bridge function declarations if a legacy copy is detected. Existing Application Passwords, `corally_suppress_deprecations`, and theme-specific Customizer CSS are preserved. Deactivating or deleting this plugin does not erase those settings. As a regular plugin it can now be deactivated from WordPress. Deprecation suppression takes effect when this plugin loads; it cannot suppress messages emitted by earlier-loading plugins.

## Connect a worker

Use a dedicated WordPress administrator account and an Application Password created in that account's WordPress profile. Send HTTP Basic authentication over HTTPS using the WordPress username and Application Password. The plugin uses WordPress authentication and capabilities; it adds no anonymous endpoints or custom authentication bypass.

```sh
curl --user 'worker-username' 'https://example.com/wp-json/corally/v1/site-info'
```

Curl prompts for the Application Password. Existing worker URLs and credentials remain valid. `site-info` includes `plugin_version`; `mu_plugin_version` remains as a compatibility alias with the same value. Scans report WordPress's cached update information. Use WordPress's update checks or the status screen to refresh it.

All endpoints require `manage_options`. CSS writes also require `edit_css`, and plugin updates require `update_plugins`, respecting WordPress file-modification restrictions and multisite permissions.

| Method | Route under `/wp-json/corally/v1` | Purpose / body |
| --- | --- | --- |
| GET | `/site-info` | WordPress/PHP/theme versions and available updates |
| GET | `/debug-log?lines=200` | Last 1–1,000 nonempty log lines; reads at most the last 256 KiB |
| POST | `/cache-flush` | Flush object cache and supported cache plugins |
| GET | `/flags` | Read deprecation-log suppression |
| POST | `/flags` | `{"suppress_log_deprecations":true}` |
| GET | `/custom-css` | Read current theme's Additional CSS |
| POST | `/custom-css` | `{"css":"body { color: #222; }","mode":"replace"}`; also supports `append` |
| POST | `/plugin-update` | `{"plugin":"akismet/akismet.php"}`; one installed plugin per call |

To update this plugin through the worker, use `{"plugin":"corally-wordpress-manager/corally-wp-developer.php"}`. Plugin updates require writable plugin files through the WordPress filesystem API. Existing response shapes are retained. Network activation is preserved when reactivation is necessary.

## How updates work

The plugin registers with WordPress's [external update hook](https://developer.wordpress.org/reference/hooks/update_plugins_hostname/) using its `Update URI`. Checks query GitHub's [latest release endpoint](https://docs.github.com/en/rest/releases/releases#get-the-latest-release). Only stable, published releases with `vMAJOR.MINOR.PATCH` (or `MAJOR.MINOR.PATCH`) tags and both named assets are accepted:

- `corally-wordpress-manager.zip`: installable archive with the stable `corally-wordpress-manager/` directory.
- `corally-wordpress-manager.json`: version, minimum WordPress version, and minimum PHP version, generated from plugin headers.

Drafts, prereleases, branch commits, automatic source archives, mismatched versions, missing assets, and assets outside this repository are not offered as updates. An empty repository or GitHub outage leaves the plugin functional and shows a diagnostic on the status page. Successful responses are cached network-wide for six hours; failures for five minutes. **Check for updates now** clears the cache and runs WordPress's update check. WordPress owns version comparison, installation, and optional automatic updates; downgrades are not offered. Auto-updates require the plugin to be active and WordPress cron to run normally.

Outbound requests go to GitHub for release metadata and its download CDN for the manifest/ZIP. WordPress site content, logs, credentials, and worker requests are not sent to GitHub.

## Develop and release

Run locally:

```sh
php tests/run.php
python3 scripts/build-release.py --tag v1.5.0
```

The build puts the installable ZIP and JSON manifest in `dist/`. It excludes tests, scripts, workflows, and development files. Tests use WordPress API doubles to exercise updater failures, filtering, caching, route permissions, and migration behavior; they do not replace a staging-site upgrade test.

To populate the initially empty repository, commit these source files (including `.github/workflows`) and push the default branch. The provided CI workflow lints and tests PHP 7.4, 8.3, and 8.5. Then:

1. Set the new version in the main plugin's `Version` header, `CORALLY_WPM_VERSION`, and `readme.txt` stable tag. Update the changelog and minimum requirement headers as needed.
2. Commit the change, tag it (first release: `v1.5.0`), and push that tag.
3. `.github/workflows/release.yml` tests the code, verifies the tag matches the plugin version, builds both assets, and publishes the GitHub release. GitHub Actions must be enabled and allowed to write repository contents.
4. Verify both assets are attached. On a staging site, use **Check for updates now**, update from the prior version, and confirm the plugin remains active and `/site-info` responds.

You can also run the release workflow manually with an existing tag. For manual publication, run the build and attach **both** files to a stable GitHub release with the matching tag. A tag alone does not publish an update. Keep published version tags immutable; use a new version for fixes.

## Changelog

### 1.5.0

- Convert the 1.4.0 MU bridge into an ordinary installable plugin.
- Preserve REST routes, authentication, stored settings, and legacy version field.
- Add GitHub release updates, native plugin details, a status screen, and manual update checks.
- Add a repeatable release build, release automation, and regression tests.
- Respect plugin-update/CSS capabilities and retain network activation during updates.
