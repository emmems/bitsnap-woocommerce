#!/usr/bin/env bash
# Regenerates languages/bitsnap-for-woocommerce.pot with WP-CLI in Docker (no PHP needed on the host),
# then merges new strings into the existing .po files.
set -euo pipefail
cd "$(dirname "$0")/.."

docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD":/app -w /app wordpress:cli \
	wp i18n make-pot . languages/bitsnap-for-woocommerce.pot \
	--slug=bitsnap-for-woocommerce \
	--domain=bitsnap-for-woocommerce \
	--exclude=node_modules,vendor,build,tests,bin,docs,.github,.wordpress-org,assets/js/blocks/index.js.map \
	--skip-audit

if command -v msgmerge >/dev/null 2>&1; then
	for po in languages/*.po; do
		msgmerge --update --backup=none --no-fuzzy-matching "$po" languages/bitsnap-for-woocommerce.pot
	done
else
	echo "msgmerge not found: update the .po files by hand, or install gettext." >&2
fi
