<?php

namespace App\Modules\Demo\Support;

/**
 * The single source of copy of the in-place guide of issue #74 (S18).
 *
 * Each step is three short sentences, always in the same order: what you see, what to do and how
 * you know it worked. The step names the real control of the screen with the label that control
 * has today, so the views only place the notice next to it and never write copy of their own. The
 * whole notice stays under thirty words and never repeats the figures the screen already shows.
 *
 * The third step lives on /saldos, so it names the screen with the label of the real menu link that
 * leads there («Saldos») and the real control the screen shows below the notice («Movimientos»),
 * which is also where the keyboard focus goes when the visitor closes the notice (H3a, H3b, V3).
 */
final class GuideSteps
{
    /** Step 1, on the panel: the real link to the bank screen. */
    public const PANEL = 'panel';

    /** Step 2, on the bank screen: the real «Conciliar el reparto» control. */
    public const BANK = 'bank';

    /** Step 3, on the balances screen: the real bank figures of the split. */
    public const SALDOS = 'saldos';

    /**
     * @var array<string, array{sentences: list<string>}>
     */
    private const STEPS = [
        self::PANEL => [
            'sentences' => [
                'Estás en el panel de ejemplo, con un abono de 100,00 €.',
                'Abre «Movimientos bancarios».',
                'Sabrás que avanzas cuando veas ese abono.',
            ],
        ],
        self::BANK => [
            'sentences' => [
                'Este abono de ejemplo cubre las dos cuotas.',
                'Escribe el importe de cada cuota y pulsa «Conciliar el reparto».',
                'Sabrás que funcionó cuando aparezca «Reparto conciliado desde el extracto».',
            ],
        ],
        self::SALDOS => [
            'sentences' => [
                'Estás en «Saldos», con el banco y las cuotas.',
                'Compara «Sin conciliar» con «Por cobrar»; si falta parte, vuelve a «Movimientos».',
                'Sabrás que funcionó si «Por cobrar» queda a cero.',
            ],
        ],
    ];

    /**
     * @return array<string, array{sentences: list<string>}>
     */
    public static function all(): array
    {
        return self::STEPS;
    }

    /**
     * The step, or null for a key the guide does not know, so a view can never render an empty notice.
     *
     * @return array{sentences: list<string>}|null
     */
    public static function find(string $step): ?array
    {
        return self::STEPS[$step] ?? null;
    }

    /**
     * The notice as the visitor reads it: the three sentences, in order, as one paragraph.
     */
    public static function notice(string $step): string
    {
        return implode(' ', self::STEPS[$step]['sentences'] ?? []);
    }
}
