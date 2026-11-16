<?php

namespace App\Modules\Fees\Http\Requests;

use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentRequest extends FormRequest
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
            'amount' => ['required', fn (string $attribute, mixed $value, \Closure $fail) => Money::parse((string) $value) === null
                ? $fail('Indica un importe en euros mayor que cero, con hasta dos decimales.')
                : null],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'note' => ['nullable', 'string', 'max:255'],
            'submission_key' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Indica el importe en euros.',
            'paid_on.required' => 'Indica la fecha del pago.',
            'paid_on.date_format' => 'La fecha del pago no es válida.',
            'paid_on.before_or_equal' => 'La fecha del pago no puede ser futura.',
            'method.required' => 'Indica la forma de pago.',
            'method.in' => 'La forma de pago no es válida.',
            'note.max' => 'La nota no puede superar los 255 caracteres.',
            'submission_key.required' => 'El formulario ha caducado. Recarga la página e inténtalo de nuevo.',
            'submission_key.uuid' => 'El formulario ha caducado. Recarga la página e inténtalo de nuevo.',
        ];
    }

    public function amountCents(): int
    {
        return (int) Money::parse((string) $this->input('amount'));
    }
}
