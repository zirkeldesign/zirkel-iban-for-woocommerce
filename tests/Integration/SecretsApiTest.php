<?php

declare(strict_types=1);

/**
 * The credential move against the real Secrets API and a real database.
 *
 * Skipped before WordPress 7.2, where the rest of this suite covers the same
 * gateway with the API absent. To run it, point the suite at a 7.2 install:
 * BTPW_WP_VERSION=7.2-beta4 BTPW_WP_ROOT=/some/dir composer test:integration:setup
 */

use ZirkelDesign\BankTransfersForWooCommerce\Gateway\BankTransferGateway;

const BTPW_SECRETS_NAME = 'zirkel-iban-for-woocommerce/test_secret_key';
const BTPW_SECRETS_VALUE = 'sk_test_secrets_api_probe_value';

function btpw_post_gateway_settings(string $testKey): BankTransferGateway
{
    $prefix = 'woocommerce_'.BankTransferGateway::GATEWAY_ID.'_';
    $settings = (array) get_option($prefix.'settings', []);

    $_POST = [];

    foreach ($settings as $field => $value) {
        $_POST[$prefix.$field] = $value;
    }

    $_POST[$prefix.'test_secret_key'] = $testKey;

    $gateway = new BankTransferGateway;
    $gateway->process_admin_options();
    $_POST = [];

    return $gateway;
}

function btpw_value_in_database(string $needle): int
{
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s",
        '%'.$wpdb->esc_like($needle).'%'
    ));
}

beforeEach(function (): void {
    if (! function_exists('wp_set_secret')) {
        $this->markTestSkipped('The Secrets API needs WordPress 7.2.');
    }

    $this->settings = get_option('woocommerce_stripe_bank_transfer_settings');
});

afterEach(function (): void {
    if (! function_exists('wp_set_secret')) {
        return;
    }

    wp_delete_secret(BTPW_SECRETS_NAME);
    update_option('woocommerce_stripe_bank_transfer_settings', $this->settings);
});

it('takes the plaintext out of the database on save', function (): void {
    btpw_post_gateway_settings(BTPW_SECRETS_VALUE);

    expect(btpw_value_in_database(BTPW_SECRETS_VALUE))->toBe(0)
        ->and(get_option('woocommerce_stripe_bank_transfer_settings')['test_secret_key'])->toBe('')
        ->and(wp_get_secret(BTPW_SECRETS_NAME)->reveal())->toBe(BTPW_SECRETS_VALUE);
});

it('runs the gateway from the secret on the next request', function (): void {
    btpw_post_gateway_settings(BTPW_SECRETS_VALUE);

    $gateway = new BankTransferGateway;

    expect($gateway->test_secret_key)->toBe(BTPW_SECRETS_VALUE)
        ->and($gateway->secret_key)->toBe(BTPW_SECRETS_VALUE);
});

it('keeps one version when the form is saved unchanged', function (): void {
    btpw_post_gateway_settings(BTPW_SECRETS_VALUE);
    btpw_post_gateway_settings(BTPW_SECRETS_VALUE);

    expect(wp_get_secret(BTPW_SECRETS_NAME, WP_Secret_Version::PREVIOUS))->toBeNull();
});

it('deletes the secret when the field is cleared', function (): void {
    btpw_post_gateway_settings(BTPW_SECRETS_VALUE);
    btpw_post_gateway_settings('');

    expect(wp_get_secret(BTPW_SECRETS_NAME))->toBeNull();
});

it('removes the secrets on uninstall', function (): void {
    btpw_post_gateway_settings(BTPW_SECRETS_VALUE);

    if (! defined('WP_UNINSTALL_PLUGIN')) {
        define('WP_UNINSTALL_PLUGIN', true);
    }

    require dirname(__DIR__, 2).'/uninstall.php';

    expect(wp_get_secret(BTPW_SECRETS_NAME))->toBeNull();
});
