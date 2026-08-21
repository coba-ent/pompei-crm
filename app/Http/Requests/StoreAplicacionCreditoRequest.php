<?php

namespace App\Http\Requests;

use App\Models\Compra;
use App\Models\Venta;
use App\Services\Ingresos\CreditoCliente;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validación de la aplicación de saldo a favor (spec 072, contracts §2).
 *
 * El tope es `min(crédito disponible, saldo pendiente)`. Las reglas de coherencia del origen
 * —mismo tipo, mismo cliente/proveedor, distinto del destino— las resuelve `CreditoCliente`, que es
 * quien las evalúa **dentro del lock**: acá se validan sólo forma y topes, para no dar por buena una
 * verificación que la concurrencia podría invalidar entre la validación y la escritura.
 */
class StoreAplicacionCreditoRequest extends FormRequest
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
        $destino = $this->comprobante();
        $pendiente = $destino instanceof Venta ? $destino->aCobrar() : $destino->aPagar();
        $disponible = app(CreditoCliente::class)->disponibleTotalPara($destino);
        $aplicable = max(0, min($disponible, $pendiente));

        return [
            'monto' => ['required', 'numeric', 'gt:0', 'lte:'.$aplicable],
            'fecha' => 'required|date',
            'nota' => 'nullable|string',
            'origen_id' => 'nullable|integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $esVenta = $this->comprobante() instanceof Venta;

        return [
            'monto.lte' => $esVenta
                ? 'El monto supera el saldo a favor aplicable a esta venta.'
                : 'El monto supera el saldo a favor aplicable a esta compra.',
        ];
    }

    private function comprobante(): Venta|Compra
    {
        return $this->route('venta') ?? $this->route('compra');
    }
}
