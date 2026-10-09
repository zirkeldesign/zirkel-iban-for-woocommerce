<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce\Stripe;

use Closure;
use Stripe\StripeClient;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Single construction point for the Stripe SDK client.
 *
 * Centralising creation here means the pinned API version lives in one place
 * and — importantly — gives tests a seam to inject a fake client without
 * touching the network. It is also the one spot Strauss needs to rewrite when
 * the bundled SDK is namespace-prefixed for the distribution build.
 */
final class ClientFactory
{
    /**
     * Stripe API version this plugin is written against.
     *
     * Keep this in step with the bundled SDK's own target
     * (\Stripe\Util\ApiVersion::CURRENT). Pinning behind it makes Stripe
     * return a "stripe-notice" header, which stripe-php 21+ raises as an
     * E_USER_WARNING on every single API call. It is pinned rather than read
     * from the SDK so that updating the SDK cannot silently change the API
     * behaviour the plugin was tested against.
     */
    public const API_VERSION = '2026-09-30.endive';

    /**
     * Optional test override: a closure receiving the secret key and returning
     * a client (or a duck-typed fake).
     */
    private static ?Closure $override = null;

    /**
     * Build a Stripe client for the given secret key.
     *
     * @return StripeClient|object|null Null when no key is available.
     */
    public static function make(string $secretKey): ?object
    {
        if (self::$override !== null) {
            return (self::$override)($secretKey);
        }

        if ($secretKey === '') {
            return null;
        }

        return new StripeClient([
            'api_key' => $secretKey,
            'stripe_version' => self::API_VERSION,
        ]);
    }

    /**
     * Inject a client factory (tests only).
     */
    public static function override(?Closure $override): void
    {
        self::$override = $override;
    }

    /**
     * Remove any injected factory (tests only).
     */
    public static function reset(): void
    {
        self::$override = null;
    }
}
