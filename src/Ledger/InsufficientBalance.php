<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Ledger;

use DomainException;

final class InsufficientBalance extends DomainException {}
