<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Commerce;

use MakerHearth\Architecture\Support\MoneyMath;

/**
 * A cart line item — the SNAPSHOT-ON-PURCHASE pattern.
 *
 * The unit price, discount percent, and discount source are FROZEN at
 * add-to-cart time: the line is `readonly` and carries no reference to the
 * live catalog row for pricing. Staff repricing a PassType tonight must not
 * change what's already in a member's cart, and an order settled next week
 * must show what the buyer actually agreed to.
 *
 * The cart line is also the ONLY place a price is computed — activation
 * snapshots this line onto the order and never re-prices. The `sourceId`
 * is how activation finds its way back to the pending domain row
 * (enrollment, pass purchase, …) once the order is paid — production
 * carries these as a fan of nullable `source_*_id` columns.
 *
 * @see ARCHITECTURE.md §7 — "Snapshot-on-purchase + the single paid contract"
 */
final readonly class CartLine
{
    public function __construct(
        public ProductType $productType,
        public string $description,
        public int $unitPriceCents,
        public int $quantity = 1,
        public int $discountPercent = 0,
        public ?string $discountSource = null,
        public ?string $sourceId = null,
    ) {}

    public function totalCents(): int
    {
        return MoneyMath::applyDiscount(
            MoneyMath::multiplyCents($this->unitPriceCents, $this->quantity),
            $this->discountPercent,
        );
    }
}
