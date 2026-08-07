<?php

namespace App\Http\Requests;

use App\Models\Cobro;
use App\Models\Venta;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateCobroRequest extends FormRequest
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
        /** @var Venta $venta */
        $venta = $this->route('venta');
        /** @var Cobro $cobro */
        $cobro = $this->route('cobro');
        $tope = $venta->aCobrar() + (float) $cobro->monto;

        return [
            'cuenta_tesoreria_id' => 'required|exists:cuentas_tesoreria,id',
            'monto' => ['required', 'numeric', 'gt:0', 'lte:'.max($tope, 0)],
            'fecha' => 'required|date',
            'nota' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'monto.lte' => 'El monto supera el saldo a cobrar.',
        ];
    }
}
