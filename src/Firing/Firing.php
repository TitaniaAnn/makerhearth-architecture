<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Firing;

use DateTimeImmutable;

/**
 * One member's piece (or shelf of pieces) in a kiln load, measured in
 * cubic inches. `billedAt` is the idempotency stamp the ledger re-checks
 * before writing the consume debit.
 */
final class Firing
{
    public ?DateTimeImmutable $billedAt = null;

    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly int $cubicInches,
    ) {}
}
