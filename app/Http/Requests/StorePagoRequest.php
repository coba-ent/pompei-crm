<?php

namespace App\Http\Requests;

use App\Models\Compra;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StorePagoRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'errors' => $validator->errors(),
        ], 422));
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Compra $compra */
        $compra = $this->route('compra');
        $aPagar = $compra->aPagar();

        return [
            'cuenta_tesoreria_id' => 'required|exists:cuentas_tesoreria,id',
            'monto' => ['required', 'numeric', 'gt:0', 'lte:'.max($aPagar, 0)],
            'fecha' => 'required|date',
            'nota' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'monto.lte' => 'El monto supera el saldo a pagar.',
        ];
    }
}
