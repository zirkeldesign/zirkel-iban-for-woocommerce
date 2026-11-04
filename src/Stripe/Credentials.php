<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce\Stripe;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The gateway's Stripe credentials in the WordPress Secrets API (7.2+).
 *
 * The API is optional at every step. It does not exist before 7.2, and on 7.2
 * it refuses to store anything until the site defines WP_SECRETS_KEY, so the
 * gateway settings stay the fallback and only an empty field ever reaches here.
 */
final class Credentials
{
    /** Gateway settings fields that may live in the Secrets API. */
    public const FIELDS = ['test_secret_key', 'live_secret_key', 'webhook_secret'];

    private const PREFIX = 'zirkel-iban-for-woocommerce/';

    /**
     * @return string|WP_Error|null The value, null when no secret is stored, or
     *                              WP_Error when one exists but cannot be read.
     */
    public static function read(string $field): string|WP_Error|null
    {
        if (! function_exists('wp_get_secret')) {
            return null;
        }

        $secret = wp_get_secret(self::name($field));

        if ($secret === null || is_wp_error($secret)) {
            return $secret;
        }

        return $secret->reveal();
    }

    /**
     * @return bool Whether the value is now held by the Secrets API.
     */
    public static function store(string $field, string $value): bool
    {
        if (! function_exists('wp_set_secret')) {
            return false;
        }

        return wp_set_secret(self::name($field), $value) === true;
    }

    public static function delete(string $field): void
    {
        if (function_exists('wp_delete_secret')) {
            wp_delete_secret(self::name($field));
        }
    }

    private static function name(string $field): string
    {
        return self::PREFIX.$field;
    }
}
