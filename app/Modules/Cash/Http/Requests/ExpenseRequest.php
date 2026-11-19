<?php

namespace App\Modules\Cash\Http\Requests;

use App\Modules\Cash\Models\CashExpense;
use App\Modules\Fees\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields of a cash expense, for both registering a draft (POST) and editing one (PUT). The
 * submission key only belongs to the registration form: the update works on an existing row, so a
 * repeated update of the same draft is the same edit applied twice.
 */
class ExpenseRequest extends FormRequest
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
        return [
            'spent_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', fn (string $attribute, mixed $value, \Closure $fail) => Money::parse((string) $value) === null
                ? $fail('Indica un importe en euros mayor que cero, con hasta dos decimales.')
                : null],
            'category' => ['required', Rule::in(array_keys(CashExpense::CATEGORIES))],
            'concept' => ['required', 'string', 'max:255'],
            'has_receipt' => ['nullable', 'boolean'],
            // A receipt is the preferred justification; without it, the reason explains the expense.
            'reason' => ['nullable', 'string', 'max:500', 'required_unless:has_receipt,1'],
            'submission_key' => $this->isMethod('post') ? ['required', 'uuid'] : ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'spent_on.required' => 'Indica la fecha del gasto.',
            'spent_on.date_format' => 'La fecha del gasto no es válida.',
            'spent_on.before_or_equal' => 'La fecha del gasto no puede ser futura.',
            'amount.required' => 'Indica el importe en euros.',
            'category.required' => 'Indica la categoría del gasto.',
            'category.in' => 'La categoría del gasto no es válida.',
            'concept.required' => 'Indica el concepto del gasto.',
            'concept.max' => 'El concepto no puede superar los 255 caracteres.',
            'reason.required_unless' => 'Indica el motivo del gasto: no hay justificante.',
            'reason.max' => 'El motivo no puede superar los 500 caracteres.',
            'submission_key.required' => 'El formulario ha caducado. Recarga la página e inténtalo de nuevo.',
            'submission_key.uuid' => 'El formulario ha caducado. Recarga la página e inténtalo de nuevo.',
        ];
    }

    public function amountCents(): int
    {
        return (int) Money::parse((string) $this->input('amount'));
    }

    public function category(): string
    {
        return (string) $this->input('category');
    }

    public function concept(): string
    {
        return trim((string) $this->input('concept'));
    }

    public function hasReceipt(): bool
    {
        return $this->boolean('has_receipt');
    }

    public function reason(): ?string
    {
        $reason = trim((string) $this->input('reason'));

        return $reason === '' ? null : $reason;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['concept' => trim((string) $this->input('concept'))]);
    }
}
