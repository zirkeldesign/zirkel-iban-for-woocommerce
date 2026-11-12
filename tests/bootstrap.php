<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/vendor/autoload.php';

/**
 * The plugin's classes guard against direct access with
 * `if (! defined('ABSPATH')) { exit; }`. Define it so autoloaded classes don't
 * terminate the test runner.
 */
if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__).'/');
}

if (! defined('BTPW_VERSION')) {
    define('BTPW_VERSION', 'test');
}
if (! defined('BTPW_URL')) {
    define('BTPW_URL', 'https://example.test/wp-content/plugins/zirkel-iban-for-woocommerce/');
}

/**
 * In-memory option store, resettable per test.
 *
 * @var array<string, mixed>
 */
$GLOBALS['btpw_test_options'] = [];
$GLOBALS['btpw_test_gateway_settings'] = [];

function btpw_test_set_option(string $key, mixed $value): void
{
    $GLOBALS['btpw_test_options'][$key] = $value;
}

function btpw_test_set_gateway_setting(string $key, mixed $value): void
{
    $GLOBALS['btpw_test_gateway_settings'][$key] = $value;
}

function btpw_test_reset(): void
{
    $GLOBALS['btpw_test_options'] = [];
    $GLOBALS['btpw_test_orders'] = [];
    $GLOBALS['btpw_test_gateway_settings'] = [];
    $GLOBALS['btpw_test_filters'] = [];
}

/**
 * A duck-typed Stripe client whose paymentIntents->create() returns a fake
 * PaymentIntent, for testing the payment flow without the network.
 */
function btpw_fake_stripe_client(string $intentId = 'pi_test', string $status = 'requires_action'): object
{
    $paymentIntents = new class($intentId, $status)
    {
        public function __construct(private string $id, private string $status) {}

        /** @param array<string, mixed> $data */
        public function create(array $data): object
        {
            return (object) [
                'id' => $this->id,
                'status' => $this->status,
                'next_action' => (object) [
                    'display_bank_transfer_instructions' => (object) ['financial_addresses' => []],
                ],
            ];
        }
    };

    $refunds = new class
    {
        /** @param array<string, mixed> $data */
        public function create(array $data): object
        {
            $GLOBALS['btpw_test_last_refund'] = $data;

            return (object) ['id' => 're_test', 'status' => 'succeeded'];
        }
    };

    $customers = new class
    {
        /** @param array<string, mixed> $data */
        public function create(array $data): object
        {
            $GLOBALS['btpw_test_last_customer'] = $data;

            return (object) ['id' => 'cus_test'];
        }
    };

    return (object) [
        'paymentIntents' => $paymentIntents,
        'refunds' => $refunds,
        'customers' => $customers,
    ];
}

/*
 * ---------------------------------------------------------------------------
 * WordPress function stubs
 * ---------------------------------------------------------------------------
 */

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
if (! function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
if (! function_exists('_x')) {
    function _x(string $text, string $context, string $domain = 'default'): string
    {
        return $text;
    }
}
if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return $text;
    }
}
if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return $text;
    }
}
if (! function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return $url;
    }
}
if (! function_exists('wp_kses_post')) {
    function wp_kses_post(string $text): string
    {
        return $text;
    }
}
if (! function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $callbacks = $GLOBALS['btpw_test_filters'][$hook] ?? [];

        ksort($callbacks);

        foreach ($callbacks as $byPriority) {
            foreach ($byPriority as $cb) {
                $value = $cb($value, ...$args);
            }
        }

        return $value;
    }
}
if (! function_exists('add_filter')) {
    function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
    {
        $GLOBALS['btpw_test_filters'][$hook][$priority][] = $cb;

        return true;
    }
}
if (! function_exists('add_action')) {
    function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
    {
        return true;
    }
}
if (! function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void {}
}
if (! function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed
    {
        return $GLOBALS['btpw_test_options'][$key] ?? $default;
    }
}
if (! function_exists('update_option')) {
    function update_option(string $key, mixed $value): bool
    {
        $GLOBALS['btpw_test_options'][$key] = $value;

        return true;
    }
}
if (! function_exists('get_user_meta')) {
    function get_user_meta(int $userId, string $key, bool $single = false): mixed
    {
        return $GLOBALS['btpw_test_options']['user_meta_'.$userId.'_'.$key] ?? '';
    }
}
if (! function_exists('update_user_meta')) {
    function update_user_meta(int $userId, string $key, mixed $value): bool
    {
        $GLOBALS['btpw_test_options']['user_meta_'.$userId.'_'.$key] = $value;

        return true;
    }
}
if (! function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string
    {
        return 'Test Shop';
    }
}
if (! function_exists('wc_price')) {
    function wc_price(float $price, array $args = []): string
    {
        return number_format($price, 2, '.', '').' '.((string) ($args['currency'] ?? 'EUR'));
    }
}
if (! function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string
    {
        return trim(strip_tags($text));
    }
}
if (! function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, mixed $args = false): void {}
}
if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data): string|false
    {
        return json_encode($data);
    }
}
if (! function_exists('absint')) {
    function absint(mixed $number): int
    {
        return abs((int) $number);
    }
}
if (! function_exists('wc_format_decimal')) {
    function wc_format_decimal(mixed $number, mixed $dp = false): string
    {
        return (string) (float) $number;
    }
}
if (! function_exists('wc_add_notice')) {
    function wc_add_notice(string $message, string $type = 'success'): void {}
}
if (! function_exists('wc_reduce_stock_levels')) {
    function wc_reduce_stock_levels(int $orderId): void {}
}
if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim($str);
    }
}
if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return $value;
    }
}
if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public string $code = '', public string $message = '') {}

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}
if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $thing): bool
    {
        return $thing instanceof WP_Error;
    }
}
if (! function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return 'https://example.test/wp-json/'.ltrim($path, '/');
    }
}
if (! function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/'.ltrim($path, '/');
    }
}

/*
 * ---------------------------------------------------------------------------
 * WooCommerce order registry + lookups
 * ---------------------------------------------------------------------------
 */

$GLOBALS['btpw_test_orders'] = [];

if (! function_exists('wc_get_order')) {
    function wc_get_order(mixed $orderId): WC_Order|false
    {
        return $GLOBALS['btpw_test_orders'][(int) $orderId] ?? false;
    }
}
if (! function_exists('wc_get_orders')) {
    /**
     * @param  array<string, mixed>  $args
     * @return array<int, WC_Order>
     */
    function wc_get_orders(array $args): array
    {
        if (isset($args['meta_value'])) {
            foreach ($GLOBALS['btpw_test_orders'] as $order) {
                if ($order->get_meta('_stripe_payment_intent_id') === $args['meta_value']) {
                    return [$order];
                }
            }
        }

        return [];
    }
}

/**
 * Minimal WC_Payment_Gateway base so the gateway can be instantiated.
 */
if (! class_exists('WC_Payment_Gateway')) {
    class WC_Payment_Gateway
    {
        public string $id = '';

        public string $icon = '';

        public bool $has_fields = false;

        public string $method_title = '';

        public string $method_description = '';

        public string $title = '';

        public string $description = '';

        public string $enabled = 'no';

        /** @var array<string, mixed> */
        public array $form_fields = [];

        /** @var array<string, mixed> */
        public array $settings = [];

        /** @var array<int, string> */
        public array $supports = ['products'];

        public function init_settings(): void
        {
            $this->settings = [];
        }

        public function get_option(string $key, mixed $default = ''): mixed
        {
            return $GLOBALS['btpw_test_gateway_settings'][$key] ?? ($this->settings[$key] ?? $default);
        }

        public function supports(string $feature): bool
        {
            return in_array($feature, $this->supports, true);
        }

        public function is_available(): bool
        {
            // Matches WooCommerce: a disabled gateway is never available.
            return $this->enabled === 'yes';
        }

        public function get_return_url(mixed $order = null): string
        {
            return 'https://example.test/checkout/order-received/';
        }

        public function process_admin_options(): bool
        {
            return true;
        }
    }
}

/**
 * Minimal WC_Order fake capturing status/note/meta mutations for assertions.
 */
if (! class_exists('WC_Order')) {
    class WC_Order
    {
        /** @var array<string, mixed> */
        public array $meta = [];

        /** @var array<int, string> */
        public array $notes = [];

        public string $status = 'pending';

        public ?string $completed_with = null;

        public bool $saved = false;

        public function __construct(
            private int $id = 1,
            private string $currency = 'EUR',
            private float $total = 100.0,
            private string $email = 'buyer@example.test',
            private string $paymentMethod = 'stripe_bank_transfer',
        ) {}

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_currency(): string
        {
            return $this->currency;
        }

        public function get_total(): float
        {
            return $this->total;
        }

        public function get_billing_email(): string
        {
            return $this->email;
        }

        public function get_billing_first_name(): string
        {
            return 'Ada';
        }

        public function get_billing_last_name(): string
        {
            return 'Lovelace';
        }

        public function get_order_number(): string
        {
            return (string) $this->id;
        }

        public function get_payment_method(): string
        {
            return $this->paymentMethod;
        }

        public function get_customer_id(): int
        {
            return 0; // Guest order unless a test says otherwise.
        }

        public function get_meta(string $key): mixed
        {
            return $this->meta[$key] ?? '';
        }

        public function update_meta_data(string $key, mixed $value): void
        {
            $this->meta[$key] = $value;
        }

        public function save(): void
        {
            $this->saved = true;
        }

        public function update_status(string $status, string $note = ''): void
        {
            $this->status = $status;
            if ($note !== '') {
                $this->notes[] = $note;
            }
        }

        public function add_order_note(string $note): void
        {
            $this->notes[] = $note;
        }

        public function payment_complete(string $transactionId = ''): void
        {
            $this->status = 'processing';
            $this->completed_with = $transactionId;
        }

        public function has_status(string $status): bool
        {
            return $this->status === $status;
        }
    }
}
