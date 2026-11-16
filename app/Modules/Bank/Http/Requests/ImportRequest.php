<?php

namespace App\Modules\Bank\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('edit-treasury') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:1024', 'extensions:csv,txt']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Selecciona un archivo CSV.',
            'file.file' => 'El archivo no se ha subido correctamente.',
            'file.max' => 'El archivo no puede superar 1 MB.',
            'file.extensions' => 'El archivo debe ser un CSV (.csv o .txt).',
        ];
    }
}
