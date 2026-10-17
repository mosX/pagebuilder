<?php

namespace App\Support\Automation;

/**
 * How many automations deep the current change runs. Record events carry it, so an
 * automation that changes records cannot trigger automations in an endless loop.
 */
final class AutomationDepth
{
    public const MAX = 3;

    private static int $depth = 0;

    public static function current(): int
    {
        return self::$depth;
    }

    /**
     * Runs the callback as an automation of the given depth.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(int $depth, callable $callback): mixed
    {
        $previous = self::$depth;
        self::$depth = $depth;

        try {
            return $callback();
        } finally {
            self::$depth = $previous;
        }
    }
}
