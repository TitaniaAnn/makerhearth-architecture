<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Commerce;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case AWAITING_PAYMENT = 'awaiting_payment';
    case PAID = 'paid';
}
