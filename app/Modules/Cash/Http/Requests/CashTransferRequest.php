<?php

namespace App\Modules\Cash\Http\Requests;

use App\Modules\Cash\Models\CashTransfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashTransferRequest extends FormRequest
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
            'submission_key' => ['required', 'uuid'],
            'direction' => ['required', Rule::in([CashTransfer::TO_CASH, CashTransfer::TO_BANK])],
            'description' => ['required', 'string', 'max:200'],
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
            'direction.required' => 'Elige la dirección del traspaso.',
            'direction.in' => 'Elige si el dinero va del banco a la caja o al contrario.',
            'description.required' => 'Escribe una descripción del traspaso.',
            'description.max' => 'La descripción no puede superar los 200 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['description' => trim((string) $this->input('description'))]);
    }
}
