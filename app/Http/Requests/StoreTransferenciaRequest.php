<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Movimiento entre Cuentas (US3): FR-015/FR-018. */
class StoreTransferenciaRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'Los datos ingresados no son válidos.',
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
        return [
            'fecha' => ['required', 'date'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'cuenta_salida_id' => ['required', 'exists:cuentas_tesoreria,id'],
            'cuenta_entrada_id' => ['required', 'exists:cuentas_tesoreria,id', 'different:cuenta_salida_id'],
            'observacion' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'cuenta_entrada_id.different' => 'La cuenta de entrada debe ser distinta de la de salida.',
        ];
    }
}
