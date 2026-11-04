<?php

declare(strict_types=1);

use ZirkelDesign\BankTransfersForWooCommerce\Gateway\BankTransferGateway;
use ZirkelDesign\BankTransfersForWooCommerce\Stripe\Credentials;

/*
 * Fakes for the WordPress 7.2 Secrets API. With an empty store they behave like
 * a site that has nothing in it; the real API is covered by SecretsApiTest, and
 * the integration suite on older WordPress covers the functions being absent.
 */
if (! function_exists('wp_get_secret')) {
    function wp_get_secret(string $name): mixed
    {
        if (isset($GLOBALS['btpw_test_secret_read_error'])) {
            return $GLOBALS['btpw_test_secret_read_error'];
        }

        if (! isset($GLOBALS['btpw_test_secrets'][$name])) {
            return null;
        }

        $value = $GLOBALS['btpw_test_secrets'][$name];

        return new class($value)
        {
            public function __construct(private string $value) {}

            public function reveal(): string
            {
                return $this->value;
            }
        };
    }

    function wp_set_secret(string $name, string $value): mixed
    {
        if (isset($GLOBALS['btpw_test_secret_write_error'])) {
            return $GLOBALS['btpw_test_secret_write_error'];
        }

        $GLOBALS['btpw_test_secret_writes'][] = $name;
        $GLOBALS['btpw_test_secrets'][$name] = $value;

        return true;
    }

    function wp_delete_secret(string $name): bool
    {
        unset($GLOBALS['btpw_test_secrets'][$name]);

        return true;
    }
}

const BTPW_SECRET_NAME = 'zirkel-iban-for-woocommerce/test_secret_key';

function btpw_test_save_gateway(array $posted): BankTransferGateway
{
    $gateway = new BankTransferGateway;
    $GLOBALS['btpw_test_posted_settings'] = $posted;
    $gateway->process_admin_options();

    return $gateway;
}

beforeEach(function (): void {
    $GLOBALS['btpw_test_secrets'] = [];
    $GLOBALS['btpw_test_secret_writes'] = [];
    unset(
        $GLOBALS['btpw_test_secret_read_error'],
        $GLOBALS['btpw_test_secret_write_error'],
        $GLOBALS['btpw_test_posted_settings'],
    );
});

describe('reading a credential', function (): void {
    it('prefers a value in the settings over the Secrets API', function (): void {
        btpw_test_set_gateway_setting('test_secret_key', 'sk_test_option');
        $GLOBALS['btpw_test_secrets'][BTPW_SECRET_NAME] = 'sk_test_secret';

        expect((new BankTransferGateway)->get_option('test_secret_key'))->toBe('sk_test_option');
    });

    it('reads an empty field from the Secrets API', function (): void {
        $GLOBALS['btpw_test_secrets'][BTPW_SECRET_NAME] = 'sk_test_secret';

        expect((new BankTransferGateway)->get_option('test_secret_key'))->toBe('sk_test_secret');
    });

    it('stays empty when nothing is stored anywhere', function (): void {
        expect((new BankTransferGateway)->get_option('test_secret_key'))->toBe('');
    });

    it('leaves fields that are not credentials alone', function (): void {
        $GLOBALS['btpw_test_secrets']['zirkel-iban-for-woocommerce/title'] = 'never read';

        expect((new BankTransferGateway)->get_option('title'))->not->toBe('never read');
    });

    it('builds no client from an unreadable secret and says so on the settings screen', function (): void {
        $GLOBALS['btpw_test_secret_read_error'] = new WP_Error('secret_key_unavailable', 'No key');
        btpw_test_set_gateway_setting('testmode', 'yes');
        $_GET = ['page' => 'wc-settings', 'tab' => 'checkout', 'section' => BankTransferGateway::GATEWAY_ID];

        $gateway = new BankTransferGateway;

        ob_start();
        $gateway->admin_notices();
        $notice = (string) ob_get_clean();
        $_GET = [];

        expect($gateway->secret_key)->toBe('')
            ->and($notice)->toContain('could not be read');
    });

    it('shows no notice when nothing went wrong', function (): void {
        $_GET = ['page' => 'wc-settings', 'tab' => 'checkout', 'section' => BankTransferGateway::GATEWAY_ID];

        ob_start();
        (new BankTransferGateway)->admin_notices();
        $notice = (string) ob_get_clean();
        $_GET = [];

        expect($notice)->not->toContain('could not be read');
    });
});

describe('offering the payment method', function (): void {
    beforeEach(function (): void {
        btpw_test_set_gateway_setting('enabled', 'yes');
        btpw_test_set_gateway_setting('testmode', 'yes');
    });

    it('hides it when the key in use cannot be read', function (): void {
        $GLOBALS['btpw_test_secret_read_error'] = new WP_Error('secret_key_unavailable', 'No key');

        expect((new BankTransferGateway)->is_available())->toBeFalse();
    });

    it('keeps it when only the key for the other mode cannot be read', function (): void {
        btpw_test_set_gateway_setting('test_secret_key', 'sk_test_option');
        $GLOBALS['btpw_test_secret_read_error'] = new WP_Error('secret_key_unavailable', 'No key');

        expect((new BankTransferGateway)->is_available())->toBeTrue();
    });

    it('keeps it for a site that has simply not entered a key yet', function (): void {
        expect((new BankTransferGateway)->is_available())->toBeTrue();
    });
});

describe('saving the settings', function (): void {
    it('moves a credential into the Secrets API and empties the field', function (): void {
        $gateway = btpw_test_save_gateway(['test_secret_key' => 'sk_test_new']);

        expect($GLOBALS['btpw_test_secrets'][BTPW_SECRET_NAME])->toBe('sk_test_new')
            ->and($gateway->settings['test_secret_key'])->toBe('')
            ->and($GLOBALS['btpw_test_options'][$gateway->get_option_key()]['test_secret_key'])->toBe('');
    });

    it('keeps the credential in the settings when the Secrets API refuses it', function (): void {
        $GLOBALS['btpw_test_secret_write_error'] = new WP_Error('secret_key_unavailable', 'No key');

        $gateway = btpw_test_save_gateway(['test_secret_key' => 'sk_test_new']);

        expect($gateway->settings['test_secret_key'])->toBe('sk_test_new')
            ->and($GLOBALS['btpw_test_secrets'])->toBeEmpty();
    });

    it('does not write a new version when the value did not change', function (): void {
        $GLOBALS['btpw_test_secrets'][BTPW_SECRET_NAME] = 'sk_test_same';

        $gateway = btpw_test_save_gateway(['test_secret_key' => 'sk_test_same']);

        expect($GLOBALS['btpw_test_secret_writes'])->toBeEmpty()
            ->and($gateway->settings['test_secret_key'])->toBe('');
    });

    it('deletes the secret when the merchant clears the field', function (): void {
        $GLOBALS['btpw_test_secrets'][BTPW_SECRET_NAME] = 'sk_test_old';

        btpw_test_save_gateway(['test_secret_key' => '']);

        expect($GLOBALS['btpw_test_secrets'])->toBeEmpty();
    });

    it('keeps an unreadable secret when the form comes back empty', function (): void {
        $GLOBALS['btpw_test_secrets'][BTPW_SECRET_NAME] = 'sk_test_locked';
        $GLOBALS['btpw_test_secret_read_error'] = new WP_Error('secret_key_unavailable', 'No key');

        btpw_test_save_gateway(['test_secret_key' => '']);

        expect($GLOBALS['btpw_test_secrets'][BTPW_SECRET_NAME])->toBe('sk_test_locked');
    });

    it('never moves anything but the three credentials', function (): void {
        expect(Credentials::FIELDS)->toBe(['test_secret_key', 'live_secret_key', 'webhook_secret']);

        btpw_test_save_gateway(['title' => 'Bank transfer']);

        expect($GLOBALS['btpw_test_secrets'])->toBeEmpty();
    });
});
