<?php

namespace App\Modules\Access\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'digits:6']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Introduce el código de 6 dígitos.',
            'code.digits' => 'El código debe tener 6 dígitos.',
        ];
    }
}
