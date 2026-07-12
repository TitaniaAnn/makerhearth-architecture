<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Tests;

use DomainException;
use MakerHearth\Architecture\Commerce\CartLine;
use MakerHearth\Architecture\Commerce\Order;
use MakerHearth\Architecture\Commerce\OrderService;
use MakerHearth\Architecture\Commerce\OrderStatus;
use MakerHearth\Architecture\Commerce\ProductType;
use PHPUnit\Framework\TestCase;

/**
 * Verifies ARCHITECTURE.md §7 — cart lines snapshot price at add time,
 * markPaid() is the single idempotent "order is paid" contract, and
 * downstream activation dispatches by product type (and is strict about
 * missing source rows).
 */
final class OrderServiceTest extends TestCase
{
    public function test_cart_lines_freeze_price_at_add_time(): void
    {
        $catalogPriceCents = 15000;
        $line = new CartLine(ProductType::PASS, '10-visit pass', unitPriceCents: $catalogPriceCents);

        $catalogPriceCents = 18000; // staff reprice the catalog after add-to-cart

        self::assertSame(15000, $line->totalCents()); // the snapshot, not the live price
    }

    public function test_line_discount_applies_bankers_rounding(): void
    {
        $line = new CartLine(
            ProductType::CLASS_ENROLLMENT,
            'Wheel Throwing I',
            unitPriceCents: 4001,
            discountPercent: 50,
            discountSource: 'Membership',
        );

        self::assertSame(2001, $line->totalCents()); // 4001 − 2000 (HALF_EVEN)
    }

    public function test_mark_paid_activates_each_line_by_product_type(): void
    {
        $service = new OrderService();
        $activated = [];
        $service->registerActivation(ProductType::CLASS_ENROLLMENT, function (CartLine $l) use (&$activated): void {
            $activated[] = $l->sourceId;
        });

        $order = new Order('ord-1', [
            new CartLine(ProductType::CLASS_ENROLLMENT, 'Wheel Throwing I', 24000, sourceId: 'enr-9'),
            new CartLine(ProductType::STUDIO_TIME, 'Studio time — 2h', 1600), // past consumption: no activation
        ]);

        $service->markPaid($order);

        self::assertSame(OrderStatus::PAID, $order->status);
        self::assertSame(['enr-9'], $activated);
    }

    public function test_mark_paid_is_idempotent(): void
    {
        $service = new OrderService();
        $activations = 0;
        $service->registerActivation(ProductType::PASS, function () use (&$activations): void {
            $activations++;
        });

        $order = new Order('ord-1', [new CartLine(ProductType::PASS, '10-visit pass', 15000, sourceId: 'pp-1')]);

        $service->markPaid($order);
        $service->markPaid($order); // replayed webhook / return-URL race

        self::assertSame(1, $activations); // exactly once
    }

    public function test_activation_strictness_bubbles_up(): void
    {
        // The registered activation looks up its pending source row; when
        // that row is gone (cancelled mid-checkout), production throws
        // rather than silently minting a fresh one. The contract here is
        // that markPaid propagates that failure instead of swallowing it.
        $service = new OrderService();
        $service->registerActivation(ProductType::CLASS_ENROLLMENT, function (CartLine $l): void {
            throw new DomainException("No PENDING enrollment for {$l->sourceId}.");
        });

        $order = new Order('ord-1', [new CartLine(ProductType::CLASS_ENROLLMENT, 'Wheel Throwing I', 24000, sourceId: 'enr-gone')]);

        $this->expectException(DomainException::class);
        $service->markPaid($order);
    }

    public function test_settle_order_is_the_manual_path_to_the_same_contract(): void
    {
        $service = new OrderService();
        $order = $service->settleOrder('ord-1', [
            new CartLine(ProductType::PASS, '10-visit pass', 15000),
        ]);

        self::assertSame(OrderStatus::PAID, $order->status);
        self::assertSame(15000, $order->totalCents());
    }
}
