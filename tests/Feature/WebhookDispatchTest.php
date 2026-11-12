<?php

declare(strict_types=1);

use ZirkelDesign\BankTransfersForWooCommerce\Webhook\WebhookHandler;

/**
 * Build a Stripe-style event array for dispatch().
 *
 * @param  array<string, mixed>  $object
 * @return array<string, mixed>
 */
function btpw_event(string $type, array $object): array
{
    return ['type' => $type, 'data' => ['object' => $object]];
}

function btpw_register_order(WC_Order $order): void
{
    $GLOBALS['btpw_test_orders'][$order->get_id()] = $order;
}

describe('WebhookHandler::dispatch order resolution', function (): void {
    it('resolves the order from PaymentIntent metadata', function (): void {
        $order = new WC_Order(id: 100);
        btpw_register_order($order);

        (new WebhookHandler)->dispatch(btpw_event('payment_intent.succeeded', [
            'id' => 'pi_1',
            'metadata' => ['order_id' => 100],
        ]));

        expect($order->status)->toBe('processing')
            ->and($order->completed_with)->toBe('pi_1');
    });

    it('falls back to the stored payment-intent meta when metadata is absent', function (): void {
        $order = new WC_Order(id: 200);
        $order->update_meta_data('_stripe_payment_intent_id', 'pi_fallback');
        btpw_register_order($order);

        (new WebhookHandler)->dispatch(btpw_event('payment_intent.succeeded', [
            'id' => 'pi_fallback',
        ]));

        expect($order->status)->toBe('processing');
    });

    it('ignores events for unknown orders', function (): void {
        (new WebhookHandler)->dispatch(btpw_event('payment_intent.succeeded', [
            'id' => 'pi_missing',
            'metadata' => ['order_id' => 999],
        ]));
    })->throwsNoExceptions();
});

describe('WebhookHandler::dispatch status mapping', function (): void {
    it('completes the order on payment_intent.succeeded', function (): void {
        $order = new WC_Order(id: 1);
        btpw_register_order($order);

        (new WebhookHandler)->dispatch(btpw_event('payment_intent.succeeded', [
            'id' => 'pi_ok',
            'metadata' => ['order_id' => 1],
        ]));

        expect($order->status)->toBe('processing')
            ->and($order->get_meta('_stripe_payment_intent_status'))->toBe('succeeded')
            ->and($order->saved)->toBeTrue();
    });

    it('does not re-process an already completed order', function (): void {
        $order = new WC_Order(id: 2);
        $order->status = 'completed';
        btpw_register_order($order);

        (new WebhookHandler)->dispatch(btpw_event('payment_intent.succeeded', [
            'id' => 'pi_dupe',
            'metadata' => ['order_id' => 2],
        ]));

        expect($order->completed_with)->toBeNull();
    });

    it('fails the order and records the reason on payment_intent.payment_failed', function (): void {
        $order = new WC_Order(id: 3);
        btpw_register_order($order);

        (new WebhookHandler)->dispatch(btpw_event('payment_intent.payment_failed', [
            'id' => 'pi_fail',
            'metadata' => ['order_id' => 3],
            'last_payment_error' => ['message' => 'insufficient funds'],
        ]));

        expect($order->status)->toBe('failed')
            ->and($order->get_meta('_stripe_payment_intent_status'))->toBe('payment_failed')
            ->and(implode(' ', $order->notes))->toContain('insufficient funds');
    });

    it('cancels the order on payment_intent.canceled', function (): void {
        $order = new WC_Order(id: 4);
        btpw_register_order($order);

        (new WebhookHandler)->dispatch(btpw_event('payment_intent.canceled', [
            'id' => 'pi_cancel',
            'metadata' => ['order_id' => 4],
        ]));

        expect($order->status)->toBe('cancelled')
            ->and($order->get_meta('_stripe_payment_intent_status'))->toBe('canceled');
    });

    it('records processing state on payment_intent.processing', function (): void {
        $order = new WC_Order(id: 5);
        btpw_register_order($order);

        (new WebhookHandler)->dispatch(btpw_event('payment_intent.processing', [
            'id' => 'pi_proc',
            'metadata' => ['order_id' => 5],
        ]));

        expect($order->get_meta('_stripe_payment_intent_status'))->toBe('processing')
            ->and($order->notes)->not->toBeEmpty();
    });

    it('stores refreshed bank details on payment_intent.requires_action', function (): void {
        $order = new WC_Order(id: 6);
        btpw_register_order($order);

        (new WebhookHandler)->dispatch(btpw_event('payment_intent.requires_action', [
            'id' => 'pi_action',
            'metadata' => ['order_id' => 6],
            'next_action' => ['display_bank_transfer_instructions' => ['financial_addresses' => []]],
        ]));

        expect($order->get_meta('_stripe_bank_transfer_details'))->not->toBe('');
    });

    it('records an underpayment on payment_intent.partially_funded', function (): void {
        $order = new WC_Order(id: 7);
        btpw_register_order($order);

        (new WebhookHandler)->dispatch(btpw_event('payment_intent.partially_funded', [
            'id' => 'pi_partial',
            'metadata' => ['order_id' => 7],
            'amount' => 10000,
            'amount_received' => 4000,
        ]));

        expect($order->get_meta('_stripe_payment_intent_status'))->toBe('partially_funded')
            ->and($order->get_meta('_btpw_amount_received'))->toBe('4000')
            ->and(implode(' ', $order->notes))->toContain('4000');
    });

    it('ignores unknown event types without error', function (): void {
        (new WebhookHandler)->dispatch(btpw_event('payment_intent.created', ['id' => 'pi_x']));
    })->throwsNoExceptions();
});

describe('WebhookHandler::dispatch other gateways', function (): void {
    it('leaves orders of other gateways untouched', function (string $type): void {
        $order = new WC_Order(id: 300, paymentMethod: 'stripe_cc');
        btpw_register_order($order);

        (new WebhookHandler)->dispatch(btpw_event($type, [
            'id' => 'pi_card',
            'metadata' => ['order_id' => 300],
            'last_payment_error' => ['message' => 'Card declined'],
        ]));

        expect($order->status)->toBe('pending')
            ->and($order->completed_with)->toBeNull()
            ->and($order->notes)->toBe([])
            ->and($order->saved)->toBeFalse();
    })->with([
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'payment_intent.processing',
        'payment_intent.requires_action',
        'payment_intent.partially_funded',
    ]);
});
