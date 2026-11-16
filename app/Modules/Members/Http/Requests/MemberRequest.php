<?php

namespace App\Modules\Members\Http\Requests;

use App\Modules\Members\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MemberRequest extends FormRequest
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
        /** @var Member|null $member */
        $member = $this->route('member');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'ends_with:.test', Rule::unique('members', 'email')->ignore($member?->id)],
            'joined_on' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Indica el nombre completo.',
            'name.max' => 'El nombre no puede superar los 255 caracteres.',
            'email.required' => 'Indica el correo ficticio.',
            'email.email' => 'El correo no es válido.',
            'email.ends_with' => 'Usa un correo ficticio con dominio .test.',
            'email.unique' => 'Ya existe un socio con ese correo.',
            'joined_on.required' => 'Indica la fecha de alta.',
            'joined_on.date_format' => 'La fecha de alta no es válida.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => Str::lower(trim((string) $this->input('email'))),
        ]);
    }
}
