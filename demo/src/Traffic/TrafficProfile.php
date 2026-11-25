<?php

declare(strict_types=1);

namespace Tollbooth\Demo\Traffic;

final class TrafficProfile
{
    public const array CONSUMERS = [
        'mobile-app' => ['auth' => 'key', 'share' => 30, 'calls' => [
            'shop_get_product' => 22, 'shop_list_products' => 12, 'shop_add_cart_item' => 14, 'shop_get_cart' => 11, 'shop_create_cart' => 5,
            'shop_create_order' => 5, 'shop_pay_order' => 4, 'shop_get_order' => 7, 'shop_list_reviews' => 6, 'shop_track_shipment' => 3,
            'mobile_feed' => 6, 'mobile_profile' => 3, 'mobile_list_favorites' => 3, 'mobile_add_favorite' => 1, 'mobile_list_addresses' => 2,
            'mobile_get_notification_preferences' => 1, 'mobile_update_notification_preferences' => 0.3, 'shop_validate_coupon' => 1,
        ]],
        'web-front' => ['auth' => 'key', 'share' => 22, 'calls' => [
            'shop_get_product' => 20, 'shop_list_products' => 14, 'shop_search_products' => 10, 'shop_list_categories' => 4, 'shop_get_category' => 3,
            'shop_add_cart_item' => 11, 'shop_get_cart' => 9, 'shop_create_cart' => 4, 'shop_create_order' => 6, 'shop_pay_order' => 5,
            'shop_list_orders' => 4, 'shop_cancel_order' => 1, 'shop_remove_cart_item' => 3, 'shop_refund_payment' => 0.5,
            'shop_get_shipping_rates' => 4, 'shop_get_shipment' => 1.5, 'shop_list_reviews' => 4, 'shop_create_review' => 0.8, 'shop_validate_coupon' => 2,
            'mobile_profile' => 1, 'mobile_add_address' => 0.4, 'mobile_remove_address' => 0.1, 'mobile_remove_favorite' => 0.3,
        ]],
        'acme-erp' => ['auth' => 'key', 'share' => 12, 'calls' => [
            'partner_list_catalog' => 40, 'partner_update_stock' => 22, 'partner_import_orders' => 2, 'partner_list_webhooks' => 2,
            'partner_list_warehouses' => 3, 'partner_get_warehouse_stock' => 12, 'partner_list_stock_movements' => 8,
            'partner_record_stock_movement' => 8, 'partner_list_invoices' => 1, 'partner_get_invoice' => 1, 'shop_create_shipment' => 4,
        ]],
        'shopify-sync' => ['auth' => 'key', 'share' => 6, 'calls' => ['partner_list_catalog' => 60, 'partner_update_stock' => 30, 'partner_get_warehouse_stock' => 10]],
        'legacy-ios' => ['auth' => 'key', 'share' => 5, 'calls' => ['legacy_get_user_v1' => 50, 'legacy_list_products_v1' => 35, 'legacy_get_order_v1' => 15]],
        'zapier-hook' => ['auth' => 'key', 'share' => 1, 'calls' => ['shop_list_orders' => 45, 'partner_list_webhooks' => 35, 'partner_create_webhook' => 10, 'partner_delete_webhook' => 10]],
        'sandbox-test' => ['auth' => 'key', 'share' => 1, 'calls' => ['shop_list_products' => 30, 'shop_create_order' => 40, 'shop_get_order' => 30]],
        'old-integration' => ['auth' => 'key', 'share' => 0.3, 'calls' => ['shop_list_orders' => 100]],
        'admin' => ['auth' => 'user', 'share' => 0.8, 'calls' => [
            'admin_stats' => 30, 'admin_list_customers' => 20, 'admin_ban_customer' => 2, 'admin_revenue_report' => 12, 'admin_top_products' => 10,
            'admin_list_coupons' => 10, 'admin_create_coupon' => 3, 'admin_delete_coupon' => 1, 'admin_approve_refund' => 4,
        ]],
        'ada' => ['auth' => 'user', 'share' => 0.8, 'calls' => ['mobile_profile' => 40, 'mobile_feed' => 40, 'mobile_list_favorites' => 20]],
        'support-desk' => ['auth' => 'jwt', 'share' => 2, 'calls' => ['backoffice_list_tickets' => 55, 'backoffice_reply_to_ticket' => 30, 'backoffice_current_agent' => 15]],
        'reporting-bot' => ['auth' => 'jwt', 'share' => 0.4, 'calls' => ['backoffice_list_tickets' => 80, 'backoffice_reply_to_ticket' => 20]],
        'anonymous' => ['auth' => 'none', 'share' => 9, 'calls' => [
            'shop_list_products' => 35, 'shop_search_products' => 22, 'shop_get_product' => 30, 'shop_list_categories' => 5,
            'shop_get_shipping_rates' => 4, 'shop_list_reviews' => 4,
        ]],
    ];

    public const array TOKENS = ['support-desk' => 'support-desk-token', 'reporting-bot' => 'reporting-bot-token'];

    public const array LATENCY = [
        'shop_pay_order' => 380, 'shop_refund_payment' => 450, 'shop_search_products' => 190, 'shop_create_order' => 110,
        'partner_import_orders' => 520, 'partner_list_catalog' => 70, 'admin_stats' => 85, 'shop_list_orders' => 65,
        'admin_revenue_report' => 600, 'shop_track_shipment' => 140, 'partner_get_warehouse_stock' => 95, 'shop_get_shipping_rates' => 55,
    ];

    /**
     * @param array<string, float|int> $weights
     */
    public static function pick(array $weights): string
    {
        $total = array_sum($weights);
        $roll = mt_rand() / mt_getrandmax() * $total;
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }

    public static function consumer(): string
    {
        return self::pick(array_map(static fn (array $consumer): float => (float) $consumer['share'], self::CONSUMERS));
    }

    public static function call(string $consumer): string
    {
        return self::pick(self::CONSUMERS[$consumer]['calls']);
    }

    public static function path(string $template, string $call = ''): string
    {
        $path = (string) preg_replace_callback('/\{(\w+)\}/', static fn (array $match): string => match ($match[1]) {
            'productId' => 'p_'.mt_rand(1, 12),
            'slug' => ['electronics', 'home', 'garden', 'toys'][mt_rand(0, 3)],
            'code' => 'SUMMER2026',
            default => self::identifier($template),
        }, $template);

        $query = match ($call) {
            'shop_get_shipping_rates' => ['weight' => mt_rand(2, 80) / 10, 'country' => 0 === mt_rand(0, 4) ? 'BE' : 'FR', 'express' => 0 === mt_rand(0, 3) ? 'true' : 'false'],
            'shop_search_products' => ['q' => ['lamp', 'usb', 'garden', 'fox', 'espresso'][mt_rand(0, 4)]],
            'admin_revenue_report' => ['from' => '2026-10-01', 'to' => '2026-11-25', 'granularity' => ['day', 'week', 'month'][mt_rand(0, 2)]],
            'partner_get_warehouse_stock' => ['low_stock_only' => 0 === mt_rand(0, 2) ? 'true' : 'false'],
            'partner_list_catalog' => ['updated_since' => '2026-11-0'.mt_rand(1, 9)],
            default => [],
        };

        return [] === $query ? $path : $path.'?'.http_build_query($query);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function body(string $call, bool $valid = true): ?array
    {
        return match ($call) {
            'shop_add_cart_item' => ['product_id' => 'p_'.($valid ? mt_rand(1, 12) : 99), 'quantity' => $valid ? mt_rand(1, 4) : 0],
            'shop_create_order' => [
                'customer_id' => 'usr_'.mt_rand(100, 999),
                'items' => [['product_id' => 'p_'.mt_rand(1, 12), 'quantity' => $valid ? mt_rand(1, 3) : 0]],
                'shipping_address' => ['line1' => '12 rue des Lilas', 'city' => 'Lyon', 'postal_code' => $valid ? '69003' : '', 'country' => 'FR'],
            ],
            'shop_refund_payment' => ['amount' => mt_rand(500, 9000), 'reason' => 'Damaged parcel'],
            'shop_create_shipment' => ['carrier' => $valid ? 'dhl' : 'pigeon', 'weight_kg' => mt_rand(5, 120) / 10],
            'shop_create_review' => ['rating' => mt_rand(3, 5), 'comment' => $valid ? 'Great value, would buy again.' : 'Meh'],
            'shop_validate_coupon' => ['code' => $valid ? 'WELCOME15' : 'nope', 'cart_total' => mt_rand(2000, 30000)],
            'partner_update_stock' => ['stock' => $valid ? mt_rand(0, 400) : -3],
            'partner_import_orders' => ['orders' => [self::body('shop_create_order', $valid)]],
            'partner_create_webhook' => ['url' => $valid ? 'https://hooks.zapier.com/'.bin2hex(random_bytes(4)) : 'not-a-url', 'events' => ['order.created']],
            'partner_record_stock_movement' => ['warehouse_id' => 'wh_lyon', 'product_id' => 'p_'.mt_rand(1, 12), 'delta' => $valid ? mt_rand(-5, 40) ?: 1 : 0],
            'mobile_add_address' => ['line1' => '8 quai Saint-Antoine', 'city' => 'Lyon', 'postalCode' => $valid ? '69002' : '', 'country' => 'FR'],
            'mobile_update_notification_preferences' => ['orderUpdates' => true, 'promotions' => 0 === mt_rand(0, 1), 'priceDrops' => true],
            'admin_create_coupon' => ['code' => $valid ? 'XMAS'.mt_rand(10, 99).'OFF' : 'X', 'percent' => $valid ? mt_rand(5, 40) : 95],
            'backoffice_reply_to_ticket' => ['message' => 'Thanks for reaching out, a refund is on its way.', 'closeTicket' => 0 === mt_rand(0, 2)],
            default => null,
        };
    }

    private static function identifier(string $template): string
    {
        return match (true) {
            str_contains($template, '/products/') => 'p_'.(0 === mt_rand(0, 60) ? 99 : mt_rand(1, 12)),
            str_contains($template, '/carts/') => 'cart_'.mt_rand(1000, 1105),
            str_contains($template, '/v1/orders/') => (string) mt_rand(1000, 9999),
            str_ends_with($template, '/payment') => 'ord_'.(1003 + 5 * mt_rand(0, 39)),
            str_contains($template, '/orders/') => 'ord_'.mt_rand(1000, 1210),
            str_contains($template, '/payments/'), str_contains($template, '/refunds/') => 'pay_'.mt_rand(1000, 1199),
            str_contains($template, '/shipments/') => 'shp_'.mt_rand(1000, 1199),
            str_contains($template, '/reviews/') => 'rev_'.mt_rand(1, 220),
            str_contains($template, '/customers/') => 'usr_'.mt_rand(101, 106),
            str_contains($template, '/webhooks/') => 'wh_'.mt_rand(1, 2),
            str_contains($template, '/warehouses/') => ['wh_lyon', 'wh_lille', 'wh_nantes'][mt_rand(0, 2)],
            str_contains($template, '/invoices/') => \sprintf('inv_2026%02d', mt_rand(1, 11)),
            str_contains($template, '/addresses/') => 'adr_work',
            str_contains($template, '/tickets/') => 'tkt_'.mt_rand(300, 330),
            default => (string) mt_rand(1, 500),
        };
    }
}
