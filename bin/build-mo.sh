#!/usr/bin/env bash
# Compiles every languages/*.po into a .mo file. Needs msgfmt (package "gettext").
# The .mo files are generated, git-ignored, and shipped in release zips.
set -euo pipefail
cd "$(dirname "$0")/.."

if ! command -v msgfmt >/dev/null 2>&1; then
	echo "msgfmt not found. Install gettext, or run: docker run --rm -v \"\$PWD\":/app -w /app alpine:3.22 sh -c 'apk add --no-cache gettext >/dev/null && sh bin/build-mo.sh'" >&2
	exit 1
fi

for po in languages/*.po; do
	mo="${po%.po}.mo"
	msgfmt --check --output-file="$mo" "$po"
	echo "built $mo"
done
