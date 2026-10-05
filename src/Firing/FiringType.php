<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Firing;

/**
 * Which firing a drop-off is for. Each drop-off record names one, so a bisque
 * record and a glaze record of the same pot are never confused by the
 * one-payment check or a refire.
 */
enum FiringType: string
{
    case BISQUE = 'bisque';
    case GLAZE = 'glaze';
    case OTHER = 'other';
}
