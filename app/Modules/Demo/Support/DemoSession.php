<?php

namespace App\Modules\Demo\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The demo session of issue #74: the only thing it keeps is a mark in the visitor's own session
 * and the split the visitor chose, written with the scenario keys and their amounts in cents.
 *
 * The mark is not authentication. It never writes a login key, so the visitor stays a guest for
 * every guard of the application; it only tells the request middleware that this visitor asked to
 * walk the real screens against the throwaway database. The sandbox itself is rebuilt on every
 * request, so nothing else survives: no row, no file and no stable identifier.
 *
 * The exit also retires the identifier it discards. The routes of the demo hold the session lock,
 * but a response that was already rendered before the exit can still be written back afterwards,
 * and the database store creates the row of an id it no longer has. A retired id is therefore
 * refused even when its row carries the mark again: leaving the demo cannot be undone by a tab.
 */
final class DemoSession
{
    /** The session key that marks an explicit, credential-free demo visit. */
    public const MARK = 'demo.session';

    /** The single state the demo keeps between requests, as the specification names it. */
    public const SPLIT = 'demo.split';

    /** Prefix of the cache key that retires a session id the exit discarded. */
    private const RETIRED = 'demo-exit:';

    public function active(): bool
    {
        if (session(self::MARK) !== true) {
            return false;
        }

        if (Cache::get($this->retiredKey()) !== null) {
            $this->stop();

            return false;
        }

        return true;
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
     * Marks the id of this request as left for good, before the exit discards it.
     *
     * The entry lives in the cache for as long as a session may live, so it covers every response
     * of the demo that is still in flight while the visitor leaves.
     */
    public function retire(): void
    {
        Cache::put(
            $this->retiredKey(),
            true,
            now()->addMinutes((int) config('session.lifetime')),
        );
    }

    private function retiredKey(): string
    {
        return self::RETIRED.session()->getId();
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
