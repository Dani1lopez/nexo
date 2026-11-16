<?php

namespace App\Modules\Fees\Http\Requests;

use App\Modules\Fees\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FeeYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('edit-treasury') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = ['amount' => ['required', fn (string $attribute, mixed $value, \Closure $fail) => Money::parse((string) $value) === null
            ? $fail('Indica un importe en euros mayor que cero, con hasta dos decimales.')
            : null]];

        // The year is chosen when the fee is fixed and never changes afterwards.
        if ($this->route('feeYear') === null) {
            $rules['year'] = ['required', 'integer', 'between:2000,2100', Rule::unique('fee_years', 'year')];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'year.required' => 'Indica el año.',
            'year.integer' => 'El año no es válido.',
            'year.between' => 'El año debe estar entre 2000 y 2100.',
            'year.unique' => 'Ya existe una cuota fijada para ese año.',
            'amount.required' => 'Indica el importe en euros.',
        ];
    }

    public function amountCents(): int
    {
        return (int) Money::parse((string) $this->input('amount'));
    }
}
