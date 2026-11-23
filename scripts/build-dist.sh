#!/usr/bin/env bash
#
# Build the distributable plugin zip with a namespace-scoped Stripe SDK.
#
# The bundled stripe/stripe-php is prefixed into
# ZirkelDesign\BankTransfersForWooCommerce\Vendor\Stripe\... via Strauss so it
# can never collide with another Stripe plugin's bundled copy. All mutation
# happens in a throwaway build directory — the working tree stays pristine.
#
# Requires: composer, php, wp-cli (+ dist-archive command), rsync, and
# bin/strauss.phar (auto-downloaded if missing).

set -euo pipefail

SLUG="zirkel-iban-for-woocommerce"
STRAUSS_VERSION="0.29.0"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

VERSION="$(php get-version.php)"
STRAUSS_PHAR="$ROOT/bin/strauss.phar"
BUILD_ROOT="$(mktemp -d "/tmp/${SLUG}-build.XXXXXX")"
BUILD="$BUILD_ROOT/$SLUG"
ZIP="$ROOT/dist/${SLUG}-${VERSION}.zip"

cleanup() { rm -rf "$BUILD_ROOT"; }
trap cleanup EXIT

if [ ! -f "$STRAUSS_PHAR" ]; then
  echo "⬇️  Downloading strauss.phar ${STRAUSS_VERSION} ..."
  curl -fsSL -o "$STRAUSS_PHAR" \
    "https://github.com/BrianHenryIE/strauss/releases/download/${STRAUSS_VERSION}/strauss.phar"
fi

echo "📁 Staging build copy ..."
mkdir -p "$BUILD" "$ROOT/dist"
rsync -a \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='/vendor' \
  --exclude='/vendor-prefixed' \
  --exclude='dist' \
  --exclude='build' \
  ./ "$BUILD/"

cd "$BUILD"

echo "📦 Installing runtime dependencies (no-dev) ..."
composer install --no-dev --optimize-autoloader --quiet

echo "🔒 Scoping stripe-php into a private namespace ..."
php "$STRAUSS_PHAR"

echo "🧭 Regenerating the autoloader with the prefixed classes ..."
# Add the prefixed classmap now that Strauss has created vendor-prefixed/, then
# regenerate. (Kept out of the committed composer.json so a plain
# `composer install` never trips over a not-yet-existing directory.)
php -r '$f="composer.json";$j=json_decode(file_get_contents($f),true);$j["autoload"]["classmap"]=["vendor-prefixed/"];file_put_contents($f,json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");'
composer dump-autoload --classmap-authoritative --no-dev --quiet

echo "🗜️  Creating $ZIP ..."
rm -f "$ZIP"
wp dist-archive "$BUILD" "$ZIP" --plugin-dirname="$SLUG"

echo "✅ Built dist/${SLUG}-${VERSION}.zip"
