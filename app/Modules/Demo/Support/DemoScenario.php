<?php

namespace App\Modules\Demo\Support;

use InvalidArgumentException;

/**
 * The fixed, fictitious scenario of the public guided demo (issue #73).
 *
 * It only carries plain values: the in-memory database that produced them is discarded before
 * the response is rendered, so nothing here may hold a model or a connection.
 */
final readonly class DemoScenario
{
    public const SOCIA_A = 'socia_a';

    public const SOCIO_B = 'socio_b';

    /** The only keys a visitor may send; they name the scenario dues, never their database ids. */
    public const KEYS = [self::SOCIA_A, self::SOCIO_B];

    /**
     * @param  array<string, array{name: string, email: string, dueId: int}>  $members
     */
    public function __construct(
        public int $year,
        public int $feeCents,
        public int $creditCents,
        public string $concept,
        public string $occurredOn,
        public int $movementId,
        public int $actorId,
        public array $members,
    ) {}

    public function dueId(string $key): int
    {
        if (! isset($this->members[$key])) {
            throw new InvalidArgumentException('Cuota de ejemplo desconocida.');
        }

        return $this->members[$key]['dueId'];
    }

    public function memberName(string $key): string
    {
        if (! isset($this->members[$key])) {
            throw new InvalidArgumentException('Cuota de ejemplo desconocida.');
        }

        return $this->members[$key]['name'];
    }

    /**
     * @return array<string, array{name: string, email: string, dueId: int}>
     */
    public function members(): array
    {
        return $this->members;
    }

    /**
     * The scenario keys in display order, so every result lists Socia Ejemplo A before Socio Ejemplo B.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return self::KEYS;
    }
}
