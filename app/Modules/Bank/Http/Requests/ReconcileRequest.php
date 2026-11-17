<?php

namespace App\Modules\Bank\Http\Requests;

use App\Modules\Fees\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReconcileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('edit-treasury') ?? false;
    }

    /**
     * The form sends one amount per pending due, keyed by its id. An empty amount means that
     * due does not take part in this split.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'submission_key.required' => 'El formulario ha caducado. Recarga la página e inténtalo de nuevo.',
            'submission_key.uuid' => 'El formulario ha caducado. Recarga la página e inténtalo de nuevo.',
            'allocations.required' => 'Indica el importe de al menos una cuota.',
            'allocations.array' => 'Indica el importe de al menos una cuota.',
            'allocations.min' => 'Indica el importe de al menos una cuota.',
            'allocations.*.string' => 'Cada importe se escribe en euros, con hasta dos decimales.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Every rejection of the amounts themselves points at the split, not at one of its rows.
        $validator->after(function (Validator $validator): void {
            $allocations = $this->input('allocations');

            if (! is_array($allocations)) {
                return;
            }

            $decided = 0;

            foreach ($allocations as $value) {
                // An untouched row arrives as null once Laravel turns the empty string into null:
                // that due does not take part, it is never a format error.
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
    }

    /**
     * The amounts that take part in the split, in cents, keyed by due id.
     *
     * @return array<int, int>
     */
    public function allocationsCents(): array
    {
        $allocations = $this->input('allocations');
        $cents = [];

        if (! is_array($allocations)) {
            return $cents;
        }

        foreach ($allocations as $dueId => $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $parsed = Money::parse(trim((string) $value));

            if ($parsed !== null) {
                $cents[(int) $dueId] = $parsed;
            }
        }

        return $cents;
    }
}
