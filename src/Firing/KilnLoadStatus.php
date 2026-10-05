<?php

declare(strict_types=1);

namespace MakerHearth\Architecture\Firing;

/**
 * The kiln-load lifecycle. STRICTLY FORWARD — each state names the only
 * states it may move to, and the transition map below is the single source
 * of truth (services consult it; nothing writes `status` directly).
 *
 * ABORTED is reachable from any pre-terminal state and is itself terminal.
 * No transition charges anything: a piece is paid for at front-desk drop-off
 * (§4), so an aborted load's pieces are refired for free without any special
 * code — there is nothing in the kiln process that could charge them again.
 *
 * @see ARCHITECTURE.md §4 — "Strictly-forward state machines"
 */
enum KilnLoadStatus: string
{
    case LOADING = 'loading';
    case READY_TO_FIRE = 'ready_to_fire';
    case FIRING = 'firing';
    case COOLING = 'cooling';
    case READY_TO_UNLOAD = 'ready_to_unload';
    case UNLOADED = 'unloaded';
    case ABORTED = 'aborted';

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::LOADING => [self::READY_TO_FIRE, self::ABORTED],
            self::READY_TO_FIRE => [self::FIRING, self::ABORTED],
            self::FIRING => [self::COOLING, self::ABORTED],
            self::COOLING => [self::READY_TO_UNLOAD, self::ABORTED],
            self::READY_TO_UNLOAD => [self::UNLOADED, self::ABORTED],
            self::UNLOADED, self::ABORTED => [],
        }, strict: true);
    }
}
