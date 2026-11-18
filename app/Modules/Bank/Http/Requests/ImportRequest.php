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
        // The extension is a hint; the content type is the gate. A binary file renamed as .csv
        // must be rejected before the statement reader ever looks at it.
        return ['file' => [
            'required',
            'file',
            'max:1024',
            'extensions:csv,txt',
            'mimetypes:text/plain,text/csv,application/csv,text/x-csv,application/vnd.ms-excel',
        ]];
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
            'file.mimetypes' => 'El archivo debe contener texto en formato CSV.',
        ];
    }
}
