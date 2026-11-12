<?php

declare(strict_types=1);

namespace ZirkelDesign\BankTransfersForWooCommerce\Webhook;

use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;
use ZirkelDesign\BankTransfersForWooCommerce\Gateway\BankTransferGateway;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Receives Stripe webhooks and reconciles bank-transfer payment events back to
 * the corresponding WooCommerce order.
 */
final class WebhookHandler
{
    public const ROUTE_NAMESPACE = 'zirkel-iban-for-woocommerce/v1';

    public function register_routes(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            // Security is enforced by verifying the Stripe signature below, not
            // by a WordPress capability — this is a machine-to-machine endpoint.
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Handle an incoming webhook request.
     */
    public function handle_webhook(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_body();
        $sigHeader = $request->get_header('stripe-signature');

        $gateway = $this->get_gateway_instance();

        if ($gateway === null) {
            $this->log('Gateway not configured', 'error');

            return new WP_REST_Response(['error' => 'Gateway not configured'], 500);
        }

        $webhookSecret = (string) $gateway->get_option('webhook_secret');

        // Hard requirement: without a configured secret and a signature we
        // refuse to process. Never trust an unverified payload.
        if ($webhookSecret === '' || empty($sigHeader)) {
            $this->log('Webhook rejected: missing signing secret or signature header', 'error');

            return new WP_REST_Response(['error' => 'Webhook signature verification is required'], 400);
        }

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
            $eventArray = $event->toArray();

            $this->log('Webhook received: '.($eventArray['type'] ?? 'unknown'));

            $this->dispatch($eventArray);

            return new WP_REST_Response(['received' => true], 200);
        } catch (SignatureVerificationException $e) {
            $this->log('Webhook signature verification failed: '.$e->getMessage(), 'error');

            return new WP_REST_Response(['error' => 'Invalid signature'], 400);
        } catch (Throwable $e) {
            $this->log('Webhook error: '.$e->getMessage(), 'error');

            return new WP_REST_Response(['error' => 'Webhook processing error'], 500);
        }
    }

    /**
     * Route a decoded event to the appropriate handler.
     *
     * Pure of any Stripe I/O so it can be unit-tested directly.
     *
     * @param  array<string, mixed>  $event
     */
    public function dispatch(array $event): void
    {
        $type = $event['type'] ?? '';
        $object = $event['data']['object'] ?? [];

        if (! is_array($object)) {
            return;
        }

        // The endpoint receives every PaymentIntent of the account, and card or SEPA intents of
        // other gateways carry the same order_id metadata. Only bank-transfer orders are ours.
        $order = $this->get_order_from_payment_intent($object);

        if ($order !== null && $order->get_payment_method() !== BankTransferGateway::GATEWAY_ID) {
            $this->log(sprintf('Ignoring %s for order #%d, paid with %s', $type, $order->get_id(), $order->get_payment_method()));

            return;
        }

        match ($type) {
            'payment_intent.succeeded' => $this->handle_payment_succeeded($object),
            'payment_intent.payment_failed' => $this->handle_payment_failed($object),
            'payment_intent.canceled' => $this->handle_payment_canceled($object),
            'payment_intent.processing' => $this->handle_payment_processing($object),
            'payment_intent.requires_action' => $this->handle_requires_action($object),
            'payment_intent.partially_funded' => $this->handle_partially_funded($object),
            default => $this->log('Unhandled webhook event type: '.$type),
        };
    }

    /**
     * @param  array<string, mixed>  $paymentIntent
     */
    private function handle_payment_succeeded(array $paymentIntent): void
    {
        $order = $this->get_order_from_payment_intent($paymentIntent);

        if ($order === null) {
            $this->log('Order not found for payment_intent: '.($paymentIntent['id'] ?? ''), 'error');

            return;
        }

        if ($order->has_status('processing') || $order->has_status('completed')) {
            $this->log('Order #'.$order->get_id().' already processed');

            return;
        }

        $order->payment_complete($paymentIntent['id'] ?? '');
        $order->add_order_note(sprintf(
            /* translators: %s: Payment Intent ID */
            __('Stripe bank transfer payment completed. Payment Intent ID: %s', 'zirkel-iban-for-woocommerce'),
            $paymentIntent['id'] ?? ''
        ));
        $order->update_meta_data('_stripe_payment_intent_status', 'succeeded');
        $order->save();

        $this->log('Payment succeeded for order #'.$order->get_id());
    }

    /**
     * @param  array<string, mixed>  $paymentIntent
     */
    private function handle_payment_failed(array $paymentIntent): void
    {
        $order = $this->get_order_from_payment_intent($paymentIntent);

        if ($order === null) {
            $this->log('Order not found for payment_intent: '.($paymentIntent['id'] ?? ''), 'error');

            return;
        }

        $order->update_status('failed', __('Bank transfer payment failed.', 'zirkel-iban-for-woocommerce'));

        $failureMessage = $paymentIntent['last_payment_error']['message']
            ?? __('Unknown error', 'zirkel-iban-for-woocommerce');

        $order->add_order_note(sprintf(
            /* translators: 1: Failure reason, 2: Payment Intent ID */
            __('Stripe bank transfer payment failed. Reason: %1$s. Payment Intent ID: %2$s', 'zirkel-iban-for-woocommerce'),
            $failureMessage,
            $paymentIntent['id'] ?? ''
        ));
        $order->update_meta_data('_stripe_payment_intent_status', 'payment_failed');
        $order->save();

        $this->log('Payment failed for order #'.$order->get_id().': '.$failureMessage);
    }

    /**
     * @param  array<string, mixed>  $paymentIntent
     */
    private function handle_payment_canceled(array $paymentIntent): void
    {
        $order = $this->get_order_from_payment_intent($paymentIntent);

        if ($order === null) {
            $this->log('Order not found for payment_intent: '.($paymentIntent['id'] ?? ''), 'error');

            return;
        }

        $order->update_status('cancelled', __('Bank transfer payment was cancelled.', 'zirkel-iban-for-woocommerce'));
        $order->add_order_note(sprintf(
            /* translators: %s: Payment Intent ID */
            __('Stripe bank transfer payment cancelled. Payment Intent ID: %s', 'zirkel-iban-for-woocommerce'),
            $paymentIntent['id'] ?? ''
        ));
        $order->update_meta_data('_stripe_payment_intent_status', 'canceled');
        $order->save();

        $this->log('Payment canceled for order #'.$order->get_id());
    }

    /**
     * @param  array<string, mixed>  $paymentIntent
     */
    private function handle_payment_processing(array $paymentIntent): void
    {
        $order = $this->get_order_from_payment_intent($paymentIntent);

        if ($order === null) {
            $this->log('Order not found for payment_intent: '.($paymentIntent['id'] ?? ''), 'error');

            return;
        }

        $order->add_order_note(sprintf(
            /* translators: %s: Payment Intent ID */
            __('Bank transfer payment is being processed by Stripe. Payment Intent ID: %s', 'zirkel-iban-for-woocommerce'),
            $paymentIntent['id'] ?? ''
        ));
        $order->update_meta_data('_stripe_payment_intent_status', 'processing');
        $order->save();

        $this->log('Payment processing for order #'.$order->get_id());
    }

    /**
     * @param  array<string, mixed>  $paymentIntent
     */
    private function handle_requires_action(array $paymentIntent): void
    {
        $order = $this->get_order_from_payment_intent($paymentIntent);

        if ($order === null) {
            $this->log('Order not found for payment_intent: '.($paymentIntent['id'] ?? ''), 'error');

            return;
        }

        if (isset($paymentIntent['next_action']['display_bank_transfer_instructions'])) {
            $order->update_meta_data(
                '_stripe_bank_transfer_details',
                wp_json_encode($paymentIntent['next_action']['display_bank_transfer_instructions'])
            );
            $order->add_order_note(__('Bank transfer instructions have been updated.', 'zirkel-iban-for-woocommerce'));
            $order->save();

            $this->log('Bank transfer instructions updated for order #'.$order->get_id());
        }
    }

    /**
     * The customer transferred less than the order total.
     *
     * Stripe holds the part-payment in the customer balance and waits for the
     * remainder. Surface it on the order so the shop can act, and expose a hook
     * the Pro dunning module uses to chase the difference.
     *
     * @param  array<string, mixed>  $paymentIntent
     */
    private function handle_partially_funded(array $paymentIntent): void
    {
        $order = $this->get_order_from_payment_intent($paymentIntent);

        if ($order === null) {
            $this->log('Order not found for payment_intent: '.($paymentIntent['id'] ?? ''), 'error');

            return;
        }

        $received = $paymentIntent['amount_received'] ?? 0;
        $expected = $paymentIntent['amount'] ?? 0;

        $order->add_order_note(sprintf(
            /* translators: 1: Amount received, 2: Amount expected (both in the smallest currency unit). */
            __('Partial bank transfer received: %1$s of %2$s (minor units). Awaiting the remaining amount.', 'zirkel-iban-for-woocommerce'),
            (string) $received,
            (string) $expected
        ));

        $order->update_meta_data('_stripe_payment_intent_status', 'partially_funded');
        $order->update_meta_data('_btpw_amount_received', (string) $received);
        $order->save();

        /**
         * Fires when a bank transfer only partially covers the order total.
         *
         * @param  WC_Order  $order
         * @param  int|float  $received  Amount received, in minor units.
         * @param  int|float  $expected  Amount expected, in minor units.
         */
        do_action('btpw_payment_partially_funded', $order, $received, $expected);

        $this->log('Partially funded order #'.$order->get_id());
    }

    /**
     * Resolve the order for a PaymentIntent, by metadata then by stored meta.
     *
     * @param  array<string, mixed>  $paymentIntent
     */
    private function get_order_from_payment_intent(array $paymentIntent): ?WC_Order
    {
        if (! empty($paymentIntent['metadata']['order_id'])) {
            $order = wc_get_order($paymentIntent['metadata']['order_id']);
            if ($order instanceof WC_Order) {
                return $order;
            }
        }

        if (empty($paymentIntent['id'])) {
            return null;
        }

        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded (limit 1) fallback lookup, only reached when the PaymentIntent has no order_id metadata; there is no non-meta WooCommerce API for this.
        $orders = wc_get_orders([
            'limit' => 1,
            'meta_key' => '_stripe_payment_intent_id',
            'meta_value' => $paymentIntent['id'],
            'meta_compare' => '=',
        ]);
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value

        return ! empty($orders) && $orders[0] instanceof WC_Order ? $orders[0] : null;
    }

    /**
     * @return object|null The gateway instance, if registered.
     */
    private function get_gateway_instance(): ?object
    {
        if (! function_exists('WC')) {
            return null;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();

        return $gateways['stripe_bank_transfer'] ?? null;
    }

    private function log(string $message, string $level = 'info'): void
    {
        $gateway = $this->get_gateway_instance();

        if ($gateway !== null && $gateway->get_option('debug_mode') === 'yes' && function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, ['source' => 'bank-transfer-payments-webhook']);
        }
    }
}
