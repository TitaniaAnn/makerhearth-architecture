<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Commerce;

/**
 * An order: snapshotted lines + a status. Status moves only through
 * OrderService — there is no public setter, mirroring the production rule
 * that nothing but the service layer mutates domain state.
 */
final class Order
{
    private(set) OrderStatus $status = OrderStatus::PENDING;

    /** @param list<CartLine> $lines */
    public function __construct(
        public readonly string $id,
        public readonly array $lines,
    ) {}

    public function totalCents(): int
    {
        return array_sum(array_map(fn (CartLine $l) => $l->totalCents(), $this->lines));
    }

    /** @internal only OrderService calls this */
    public function forceStatus(OrderStatus $status): void
    {
        $this->status = $status;
    }
}
