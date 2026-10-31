=== Corally WordPress Manager ===
Contributors: Ville Bergman
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.6.0

Authenticated REST access for AI workers, with updates from public GitHub Releases.

== Description ==

Site scans, debug log access, cache flushing, deprecation flags, custom CSS, plugin updates and a counter for AI crawlers and AI assistant fetches. Uses WordPress Application Passwords and administrator capabilities. See README.md for routes, migration and release instructions.

== Installation ==

Remove the old corally-wp-developer.php MU plugin before activating this plugin. Upload the release asset corally-wordpress-manager.zip through Plugins > Add New. Existing settings and Application Passwords are retained.

== Changelog ==

= 1.6.0 =
Add the AI crawler counter (GPTBot, ChatGPT-User, ClaudeBot, Claude-User, PerplexityBot …) and GET /corally/v1/ai-bot-hits. Toggle with the ai_bot_counter flag.

= 1.5.0 =
Convert the MU bridge into a regular plugin. Add GitHub Releases updates, admin status, release automation and regression tests.
