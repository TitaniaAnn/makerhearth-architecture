<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Commerce;

/**
 * What a cart/order line item is FOR. Production has ~15 of these; the
 * subset here is enough to show the activation dispatch in OrderService.
 * Lines like STUDIO_TIME represent past consumption and need no activation.
 */
enum ProductType: string
{
    case CLASS_ENROLLMENT = 'class_enrollment';
    case PASS = 'pass';
    case FIRING_PACKAGE = 'firing_package';
    case MEMBERSHIP = 'membership';
    case STUDIO_TIME = 'studio_time';
}
