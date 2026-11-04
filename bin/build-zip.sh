#!/usr/bin/env bash
# Builds the release zip: dist/bitsnap-for-woocommerce.zip, with one top folder `bitsnap-for-woocommerce/`.
#
# The release workflow runs this script, and so can you. Run `npm ci && npm run build` and `bash bin/build-mo.sh`
# first: the zip needs the built block script (build/) and the compiled translations (languages/*.mo).
#
# What goes in: everything not listed in .distignore, plus Composer's production dependencies
# (`composer install --no-dev --optimize-autoloader`). Needs rsync, zip (or python3) and composer (or Docker).
set -euo pipefail
cd "$(dirname "$0")/.."

SLUG=bitsnap-for-woocommerce
OUT_DIR=${1:-dist}
STAGE="$OUT_DIR/stage"
TARGET="$STAGE/$SLUG"
ZIP="$OUT_DIR/$SLUG.zip"

if [ ! -f build/blocks.js ] || [ ! -f build/blocks.asset.php ]; then
	echo "build/ is missing: run 'npm ci && npm run build' first." >&2
	exit 1
fi
if ! ls languages/*.mo >/dev/null 2>&1; then
	echo "languages/*.mo is missing: run 'bash bin/build-mo.sh' first." >&2
	exit 1
fi

rm -rf "$STAGE" "$ZIP"
mkdir -p "$TARGET"

# 1. Plugin files, minus everything in .distignore (and the output directory itself).
rsync -a --exclude-from=.distignore --exclude="/$(basename "$OUT_DIR")" ./ "$TARGET/"

# 2. Production dependencies only. composer.json and composer.lock are removed again afterwards.
cp composer.json composer.lock "$TARGET/"
if command -v composer >/dev/null 2>&1; then
	(cd "$TARGET" && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress)
else
	docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer \
		-v "$PWD/$TARGET":/app -w /app composer:2 \
		install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress
fi
rm -f "$TARGET/composer.json" "$TARGET/composer.lock"

if [ ! -f "$TARGET/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php" ]; then
	echo "The update checker is missing from the staged vendor/ directory." >&2
	exit 1
fi

# 3. Zip it, keeping the single top folder.
if command -v zip >/dev/null 2>&1; then
	(cd "$STAGE" && zip -q -r -X "../$SLUG.zip" "$SLUG")
else
	(cd "$STAGE" && python3 -m zipfile -c "../$SLUG.zip" "$SLUG")
fi
rm -rf "$STAGE"

echo "built $ZIP"
