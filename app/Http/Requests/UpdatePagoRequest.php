<?php

namespace App\Http\Requests;

use App\Models\Compra;
use App\Models\Pago;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdatePagoRequest extends FormRequest
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
        /** @var Pago $pago */
        $pago = $this->route('pago');
        // El pago que se está editando ya está descontado de `aPagar()`: sin devolverlo al tope,
        // editar sólo la fecha de un pago total daría "supera el saldo a pagar" (mismo criterio
        // que `UpdateCobroRequest`).
        $tope = $compra->aPagar() + (float) $pago->monto;

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
            'monto.lte' => 'El monto supera el saldo a pagar.',
        ];
    }
}
