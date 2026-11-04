# Store assets (kept for a possible future listing)

The plugin is **not** published on WordPress.org: it is self-hosted and updates itself from GitHub releases. Nothing reads this directory today. It is not part of the release zip (`.distignore`) and the release workflow does not use it.

The files are kept in case the plugin is listed in the WordPress.org directory later. Then they would be copied to the `assets` directory of the plugin's SVN repository, with these names (case sensitive):

- `icon-128x128.png` and `icon-256x256.png` (plugin icon)
- `banner-772x250.png` and `banner-1544x500.png` (plugin page banner)
- `screenshot-1.png` ... `screenshot-4.png` (listed under `== Screenshots ==` in `readme.txt`; taken 2026-11-04 in a live test against bitsnap.pl)

Do not add the Bitsnap logo without checking its usage rules.
