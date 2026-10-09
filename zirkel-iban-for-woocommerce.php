<?php

/**
 * Plugin Name:       Zirkel Virtual IBAN for WooCommerce
 * Plugin URI:        https://github.com/zirkeldesign/zirkel-iban-for-woocommerce
 * Description:       Accept reconciled bank transfer payments in WooCommerce via Stripe. Each order gets unique virtual bank account details (SEPA/ACH/Bacs/SPEI), and webhooks mark the order paid automatically.
 * Version:           1.0.2
 * Author:            zirkel.design
 * Author URI:        https://zirkel.design
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zirkel-iban-for-woocommerce
 * Domain Path:       /languages
 * Requires PHP:      8.3
 * Requires at least: 6.5
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * WC tested up to:   11.2
 */

declare(strict_types=1);

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use ZirkelDesign\BankTransfersForWooCommerce\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

define('BTPW_VERSION', '1.0.2');
define('BTPW_FILE', __FILE__);
define('BTPW_DIR', plugin_dir_path(__FILE__));
define('BTPW_URL', plugin_dir_url(__FILE__));

/**
 * Load the Composer autoloader (present in the distributed build).
 */
if (file_exists(BTPW_DIR.'vendor/autoload.php')) {
    require_once BTPW_DIR.'vendor/autoload.php';
}

/**
 * Declare HPOS (custom order tables) compatibility.
 */
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/**
 * Boot the plugin once all plugins are loaded, or warn if WooCommerce is absent.
 */
add_action('plugins_loaded', static function (): void {
    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            if (! current_user_can('activate_plugins')) {
                return;
            }

            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html__('Zirkel Virtual IBAN for WooCommerce requires WooCommerce to be installed and activated.', 'zirkel-iban-for-woocommerce')
            );
        });

        return;
    }

    Plugin::boot();
}, 11);

/**
 * Flush rewrite rules on activation/deactivation so the REST webhook route is
 * registered/removed cleanly.
 */
register_activation_hook(__FILE__, 'flush_rewrite_rules');
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

/**
 * Add a Settings shortcut on the plugins list.
 */
add_filter('plugin_action_links_'.plugin_basename(__FILE__), static function (array $links): array {
    $settingsUrl = admin_url('admin.php?page=wc-settings&tab=checkout&section=stripe_bank_transfer');
    array_unshift(
        $links,
        '<a href="'.esc_url($settingsUrl).'">'.esc_html__('Settings', 'zirkel-iban-for-woocommerce').'</a>'
    );

    return $links;
});
