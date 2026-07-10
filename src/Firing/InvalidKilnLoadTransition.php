<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Firing;

use DomainException;

final class InvalidKilnLoadTransition extends DomainException
{
    public static function between(KilnLoadStatus $from, KilnLoadStatus $to): self
    {
        return new self("Cannot transition a kiln load from {$from->value} to {$to->value}.");
    }
}
