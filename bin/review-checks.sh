#!/usr/bin/env bash
set -euo pipefail
# Docker is required: run in a disposable Boat VM with only this repository.
docker run --rm -v "$PWD:/app" -w /app composer:2 sh -c 'git config --global --add safe.directory /app && composer install --no-interaction --prefer-dist --no-progress'
for version in 7.4 8.3; do
  docker run --rm -v "$PWD:/app" -w /app "php:$version-cli" vendor/bin/phpunit
done
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli vendor/bin/phpcs --no-cache
npm ci --no-audit --no-fund
npm run build
trap 'printf "y\n" | npx wp-env destroy' EXIT
# A cold MariaDB container can become reachable after wp-env first probes it.
npx wp-env start || npx wp-env start
for hpos in no yes; do
  npx wp-env run cli wp wc cot sync
  npx wp-env run cli wp option update woocommerce_custom_orders_table_enabled "$hpos"
  npm run test:smoke
done
