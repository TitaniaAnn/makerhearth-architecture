<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Commerce;

use DomainException;

/**
 * markPaid() is THE SINGLE CONTRACT for "this order has been paid."
 *
 * Every payment path converges here:
 *
 *   Stripe webhook ──▶ markPaidFromIntent() ─┐
 *                                            ├──▶ markPaid()
 *   staff / kiosk / no-Stripe settle ────────┘
 *
 * and every downstream activation — enrollment confirmed, pass minted,
 * firing package credited, membership activated — hangs off markPaid()'s
 * dispatch-by-product-type, so it is impossible to pay an order through any
 * path and skip an activation.
 *
 * Two properties the tests pin down:
 *
 *   1. IDEMPOTENT. An already-PAID order returns unchanged — the Stripe
 *      webhook and the checkout return URL both call in (whichever wires up
 *      first wins), and a replayed webhook must not double-activate. In
 *      production each activation is additionally keyed on its source order
 *      line so even a bug here can't double-mint.
 *   2. STRICT. Activation looks up the pending source row (via the line's
 *      sourceId); if it's gone — cancelled mid-checkout — it THROWS rather
 *      than silently creating a fresh ENROLLED/BOOKED row nobody asked for.
 *
 * @see ARCHITECTURE.md §7 — "Snapshot-on-purchase + the single paid contract"
 */
final class OrderService
{
    /** @var array<string, callable(CartLine): void> keyed by ProductType->value */
    private array $activations = [];

    /**
     * Register the activation for a product type. Production's equivalent is
     * the `match ($line->product_type)` inside activateDownstream() calling
     * the owning domain service (EnrollmentService, PassRedemptionService, …).
     *
     * @param callable(CartLine): void $activation
     */
    public function registerActivation(ProductType $type, callable $activation): void
    {
        $this->activations[$type->value] = $activation;
    }

    /**
     * Manual settlement (staff, kiosk, POS, no-Stripe studios): cart lines →
     * PENDING order → markPaid. Stripe-paid orders never call this — the
     * webhook reaches markPaid() directly.
     *
     * @param list<CartLine> $lines
     */
    public function settleOrder(string $orderId, array $lines): Order
    {
        $order = new Order($orderId, $lines);

        return $this->markPaid($order);
    }

    public function markPaid(Order $order): Order
    {
        // Idempotency: already PAID → return unchanged (replayed webhook,
        // webhook + return-URL race). Production takes a row lock here.
        if ($order->status === OrderStatus::PAID) {
            return $order;
        }

        if (! in_array($order->status, [OrderStatus::PENDING, OrderStatus::AWAITING_PAYMENT], strict: true)) {
            throw new DomainException("Order {$order->id} cannot transition to PAID from {$order->status->value}.");
        }

        $order->forceStatus(OrderStatus::PAID);

        foreach ($order->lines as $line) {
            $this->activateDownstream($line);
        }

        return $order;
    }

    private function activateDownstream(CartLine $line): void
    {
        $activation = $this->activations[$line->productType->value] ?? null;

        // Lines like STUDIO_TIME represent past consumption — no activation
        // registered, nothing to do.
        if ($activation === null) {
            return;
        }

        $activation($line);
    }
}
