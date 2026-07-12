<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Refunds;

enum RefundTier: string
{
    case FULL = 'full';
    case PARTIAL = 'partial';
    case NONE = 'none';
}
