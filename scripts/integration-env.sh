#!/usr/bin/env bash
#
# Provisions the WordPress install the Integration suite runs against.
#
# SQLite-backed, so it needs no MySQL — only wp-cli, PHP with pdo_sqlite and
# network access for the WordPress/WooCommerce downloads. The install is cached
# in .wp-integration so repeat runs are instant; pass --fresh to rebuild it.
#
# The live Stripe suite additionally needs a Stripe TEST secret key, supplied
# out of band so it never reaches the repository:
#
#   export BTPW_STRIPE_TEST_KEY=sk_test_...      # or write .stripe-test-key
#
# Without it the environment still builds and the Stripe tests skip.

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CORE_DIR="${BTPW_CORE_SOURCE:-$(cd "$PLUGIN_DIR/../zirkel-iban-for-woocommerce" 2>/dev/null && pwd || true)}"
WP_DIR="${BTPW_WP_ROOT:-$PLUGIN_DIR/.wp-integration}"
WP_VERSION="${BTPW_WP_VERSION:-latest}"
PLUGINS_DIR="$WP_DIR/wp-content/plugins"

# wp-cli's phar can exhaust the default memory limit unpacking core. Resolve the
# binary *before* defining the wrapper — inside it, `command -v wp` would find
# the function itself and recurse.
WP_BIN="$(command -v wp || true)"

if [[ -z "$WP_BIN" ]]; then
    echo "✖ wp-cli not found on PATH." >&2
    exit 1
fi

wp() { php -d memory_limit=1024M "$WP_BIN" "$@"; }

if [[ "${1:-}" == "--fresh" ]]; then
    echo "› Removing $WP_DIR"
    rm -rf "$WP_DIR"
fi

if [[ -f "$WP_DIR/wp-load.php" ]]; then
    echo "✅ WordPress already provisioned at $WP_DIR (use --fresh to rebuild)"
else
    echo "› Downloading WordPress $WP_VERSION"
    wp core download --path="$WP_DIR" --version="$WP_VERSION" --force --quiet

    echo "› Installing the SQLite drop-in"
    curl -sL https://downloads.wordpress.org/plugin/sqlite-database-integration.zip -o "$WP_DIR/sqlite.zip"
    unzip -q -o "$WP_DIR/sqlite.zip" -d "$PLUGINS_DIR/"
    rm "$WP_DIR/sqlite.zip"
    cp "$PLUGINS_DIR/sqlite-database-integration/db.copy" "$WP_DIR/wp-content/db.php"
    php -r '
        [$f, $d] = [$argv[1], $argv[2]];
        $c = file_get_contents($f);
        $c = str_replace("{SQLITE_IMPLEMENTATION_FOLDER_PATH}", $d."/wp-content/plugins/sqlite-database-integration", $c);
        $c = str_replace("{SQLITE_PLUGIN}", "sqlite-database-integration/load.php", $c);
        file_put_contents($f, $c);
    ' "$WP_DIR/wp-content/db.php" "$WP_DIR"

    wp config create --path="$WP_DIR" --dbname=wp --dbuser=root --dbpass='' --skip-check --force --quiet
    wp core install --path="$WP_DIR" --url=http://localhost --title="BTPW Core Integration" \
        --admin_user=admin --admin_password=admin --admin_email=dev@example.test --skip-email --quiet
fi

echo "› Installing WooCommerce"
wp plugin install woocommerce --path="$WP_DIR" --activate --force --quiet

# --- the plugin under test --------------------------------------------------
echo "› Linking the plugin under test"
rm -rf "$PLUGINS_DIR/zirkel-iban-for-woocommerce"
ln -s "$PLUGIN_DIR" "$PLUGINS_DIR/zirkel-iban-for-woocommerce"
wp plugin activate zirkel-iban-for-woocommerce --path="$WP_DIR" --quiet || true

# --- storefront the browser suite can actually reach -------------------------
# None of this matters to the PHP integration suite, which boots WordPress in
# process and never speaks HTTP. The browser suite does, and without these three
# the checkout is unreachable in ways that look like plugin bugs:
#
#   permalinks   a fresh install is on "plain", so /checkout/ serves the blog
#   coming soon  WooCommerce 9.1+ puts new stores behind a launch screen
#   a product    an empty cart renders no payment methods at all
echo "› Preparing the storefront for browser tests"
wp rewrite structure '/%postname%/' --path="$WP_DIR" --quiet
wp rewrite flush --path="$WP_DIR" --quiet
wp option update woocommerce_coming_soon no --path="$WP_DIR" --quiet

# The gateway has to be switched on, or the checkout shows no payment method
# and the browser suite fails in a way that looks like a BlocksSupport bug.
# is_available() does not check the key, so the first browser test works without
# one; only the test that really places an order needs it.
# Written as an if rather than inline: under `set -e` a failing test inside a
# command substitution aborts the whole script, so a missing key file would
# take the environment build down with it - which is exactly what CI hits and
# a developer with the file never sees.
STRIPE_KEY="${BTPW_STRIPE_TEST_KEY:-}"
if [[ -z "$STRIPE_KEY" && -f "$PLUGIN_DIR/.stripe-test-key" ]]; then
    STRIPE_KEY="$(tr -d '[:space:]' < "$PLUGIN_DIR/.stripe-test-key")"
fi
BTPW_STRIPE_KEY="$STRIPE_KEY" wp eval '
    $s = (array) get_option("woocommerce_stripe_bank_transfer_settings", []);
    $s["enabled"]  = "yes";
    $s["testmode"] = "yes";
    $key = (string) getenv("BTPW_STRIPE_KEY");
    if ($key !== "") {
        $s["test_secret_key"] = $key;
    }
    $s += ["transfer_type" => "eu_bank_transfer", "default_currency" => "eur"];
    update_option("woocommerce_stripe_bank_transfer_settings", $s);
' --path="$WP_DIR" --quiet

# A fresh WooCommerce defaults to USD, and eu_bank_transfer accepts EUR only.
wp option update woocommerce_currency EUR --path="$WP_DIR" --quiet

if [[ -z "$(wp post list --post_type=product --format=ids --path="$WP_DIR" 2>/dev/null)" ]]; then
    wp wc product create --name="Lastenrad Testartikel" --type=simple --regular_price=249 \
        --status=publish --user=admin --path="$WP_DIR" --porcelain --quiet >/dev/null
fi

echo
echo "✅ Environment ready at $WP_DIR"
wp plugin list --path="$WP_DIR" --fields=name,status --format=table
