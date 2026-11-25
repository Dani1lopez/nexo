<?php

namespace App\Modules\Demo\Support;

/**
 * The demo session of issue #74: the only thing it keeps is a mark in the visitor's own session
 * and the split the visitor chose, written with the scenario keys and their amounts in cents.
 *
 * The mark is not authentication. It never writes a login key, so the visitor stays a guest for
 * every guard of the application; it only tells the request middleware that this visitor asked to
 * walk the real screens against the throwaway database. The sandbox itself is rebuilt on every
 * request, so nothing else survives: no row, no file and no stable identifier.
 */
final class DemoSession
{
    /** The session key that marks an explicit, credential-free demo visit. */
    public const MARK = 'demo.session';

    /** The single state the demo keeps between requests, as the specification names it. */
    public const SPLIT = 'demo.split';

    public function active(): bool
    {
        return session(self::MARK) === true;
    }

    /** Opening the demo always starts from the beginning, whatever a previous visit stored. */
    public function start(): void
    {
        session([self::MARK => true]);

        $this->forgetSplit();
    }

    public function stop(): void
    {
        session()->forget([self::MARK, self::SPLIT]);
    }

    /**
     * The stored split, filtered to the scenario keys and positive integer cents.
     *
     * @return array<string, int>
     */
    public function split(): array
    {
        $stored = session(self::SPLIT);

        if (! is_array($stored)) {
            return [];
        }

        $split = [];

        foreach ($stored as $key => $cents) {
            if (! is_string($key) || ! in_array($key, DemoScenario::KEYS, true)) {
                continue;
            }

            if (! is_int($cents) || $cents <= 0) {
                continue;
            }

            $split[$key] = $cents;
        }

        return $split;
    }

    /**
     * @param  array<string, int>  $split
     */
    public function store(array $split): void
    {
        session([self::SPLIT => $split]);
    }

    public function forgetSplit(): void
    {
        session()->forget(self::SPLIT);
    }
}
