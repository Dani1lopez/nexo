<?php

namespace App\Modules\Demo\Http\Requests;

use App\Modules\Demo\Support\DemoScenario;
use App\Modules\Fees\Support\Money;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Validates the guided demo split without Laravel's redirect-and-flash behaviour.
 *
 * The demo must not persist the visitor's request: a rejected POST is answered with the errors in
 * the same response, with no session flash, no old input and no redirect that carries either.
 * Amounts keep the same rules and messages as the /bank reconcile form.
 */
final class DemoReconcileRequest
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{cents: array<string, int>, errors: MessageBag}
     */
    public function validate(array $input): array
    {
        $allocations = $input['allocations'] ?? null;

        $validator = Validator::make($input, $this->rules(), $this->messages());

        $validator->after(function (ValidatorInstance $validator) use ($allocations): void {
            if (! is_array($allocations)) {
                return;
            }

            // Only the fixed scenario keys are accepted: a database id or any other key is not
            // part of the demo and must never reach the real reconciliation action.
            foreach (array_keys($allocations) as $key) {
                if (! is_string($key) || ! in_array($key, DemoScenario::KEYS, true)) {
                    $validator->errors()->add('allocations', 'Solo puedes conciliar las dos cuotas de este ejemplo.');

                    return;
                }
            }

            $decided = 0;

            foreach ($allocations as $value) {
                // An untouched row arrives as null once Laravel turns the empty string into null.
                if ($value === null) {
                    continue;
                }

                if (! is_string($value) && ! is_numeric($value)) {
                    $validator->errors()->add('allocations', 'Cada importe se escribe en euros, con hasta dos decimales.');

                    return;
                }

                $typed = trim((string) $value);

                if ($typed === '') {
                    continue;
                }

                if (Money::parse($typed) === null) {
                    $validator->errors()->add('allocations', 'Cada importe debe ser mayor que cero, con hasta dos decimales.');

                    return;
                }

                $decided++;
            }

            if ($decided === 0) {
                $validator->errors()->add('allocations', 'Indica el importe de al menos una cuota.');
            }
        });

        if ($validator->fails()) {
            return ['cents' => [], 'errors' => $validator->errors()];
        }

        return ['cents' => $this->cents($allocations), 'errors' => $validator->errors()];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'allocations.required' => 'Indica el importe de al menos una cuota.',
            'allocations.array' => 'Indica el importe de al menos una cuota.',
            'allocations.min' => 'Indica el importe de al menos una cuota.',
            'allocations.*.string' => 'Cada importe se escribe en euros, con hasta dos decimales.',
        ];
    }

    /**
     * @param  array<mixed>  $allocations
     * @return array<string, int>
     */
    private function cents(array $allocations): array
    {
        $cents = [];

        foreach ($allocations as $key => $value) {
            if (! is_string($key) || ! in_array($key, DemoScenario::KEYS, true)) {
                continue;
            }

            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $parsed = Money::parse(trim((string) $value));

            if ($parsed !== null) {
                $cents[$key] = $parsed;
            }
        }

        return $cents;
    }
}
