<?php

/**
 * Uninstall cleanup: remove the gateway's stored settings.
 *
 * Order meta (payment intent id/status, bank transfer details) is intentionally
 * left in place so historical orders remain auditable after uninstall.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('woocommerce_stripe_bank_transfer_settings');

if (function_exists('wp_delete_secret')) {
    wp_delete_secret('zirkel-iban-for-woocommerce/test_secret_key');
    wp_delete_secret('zirkel-iban-for-woocommerce/live_secret_key');
    wp_delete_secret('zirkel-iban-for-woocommerce/webhook_secret');
}
