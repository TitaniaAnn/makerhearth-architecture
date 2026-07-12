<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Firing;

/**
 * A kiln load: a batch of members' pieces fired together. Status moves only
 * through KilnLoadLifecycle's named methods — this class deliberately has no
 * public status setter.
 */
final class KilnLoad
{
    private(set) KilnLoadStatus $status = KilnLoadStatus::LOADING;

    /** @var list<Firing> */
    private array $firings = [];

    public function __construct(
        public readonly string $id,
    ) {}

    public function addFiring(Firing $firing): void
    {
        if ($this->status !== KilnLoadStatus::LOADING) {
            throw new InvalidKilnLoadTransition('Pieces can only be added while the load is still LOADING.');
        }

        $this->firings[] = $firing;
    }

    /** @return list<Firing> */
    public function firings(): array
    {
        return $this->firings;
    }

    /** @internal only KilnLoadLifecycle calls this, after consulting the map */
    public function forceStatus(KilnLoadStatus $status): void
    {
        $this->status = $status;
    }
}
