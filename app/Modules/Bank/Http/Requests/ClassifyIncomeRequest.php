<?php

namespace App\Modules\Bank\Http\Requests;

use App\Modules\Bank\Models\OtherIncome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClassifyIncomeRequest extends FormRequest
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
            'category' => ['required', Rule::in(array_keys(OtherIncome::CATEGORIES))],
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
            'category.required' => 'Elige una categoría para el ingreso.',
            'category.in' => 'Elige una de las categorías disponibles.',
            'description.required' => 'Escribe una descripción para el ingreso.',
            'description.max' => 'La descripción no puede superar los 200 caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['description' => trim((string) $this->input('description'))]);
    }
}
