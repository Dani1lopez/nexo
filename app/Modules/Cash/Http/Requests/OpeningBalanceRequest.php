<?php

namespace App\Modules\Cash\Http\Requests;

use App\Modules\Fees\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class OpeningBalanceRequest extends FormRequest
{
    /** Zero is a real base, so it is accepted here even though a fee payment must be above zero. */
    private const ZERO = '/^0(?:[.,]0{1,2})?$/';

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
            'amount' => ['required', fn (string $attribute, mixed $value, \Closure $fail) => self::parse($value) === null
                ? $fail('Indica un importe en euros igual o mayor que cero, con hasta dos decimales.')
                : null],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Indica el saldo inicial en euros.',
        ];
    }

    public function amountCents(): int
    {
        return self::parse($this->input('amount')) ?? 0;
    }

    /**
     * Reuses the euro parser of the payments, with the one difference the base needs: zero is valid.
     */
    private static function parse(mixed $value): ?int
    {
        $input = trim((string) $value);

        if (preg_match(self::ZERO, $input) === 1) {
            return 0;
        }

        return Money::parse($input);
    }
}
