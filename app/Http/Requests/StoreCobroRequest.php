<?php

namespace App\Http\Requests;

use App\Models\Venta;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreCobroRequest extends FormRequest
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
     * `monto` es el importe RECIBIDO del cliente; `vuelto` lo que se le devolvió en el acto. Lo que
     * se imputa a la venta —y lo que termina en `cobros.monto`— es el **neto** (spec 110).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Venta $venta */
        $venta = $this->route('venta');
        $aCobrar = max($venta->aCobrar(), 0);

        return [
            'cuenta_tesoreria_id' => 'required|exists:cuentas_tesoreria,id',
            // Sin vuelto el tope es el de siempre; con vuelto el monto recibido puede superarlo y
            // el límite lo pone la regla del neto en withValidator().
            'monto' => ['required', 'numeric', 'gt:0', ...($this->tieneVuelto() ? [] : ['lte:'.$aCobrar])],
            'vuelto' => ['nullable', 'numeric', 'gte:0', 'lt:monto'],
            'cuenta_vuelto_id' => [
                $this->tieneVuelto() ? 'required' : 'nullable',
                'nullable', 'exists:cuentas_tesoreria,id',
            ],
            'fecha' => 'required|date',
            'nota' => 'nullable|string',
        ];
    }

    /**
     * FR-007: con vuelto, el neto tiene que saldar **exactamente** la venta.
     *
     * El vuelto existe para cerrar la operación en el acto: si el cliente queda debiendo, lo que
     * corresponde es una cobranza parcial común, sin vuelto.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (! $this->tieneVuelto() || $v->errors()->hasAny(['monto', 'vuelto'])) {
                return;
            }

            /** @var Venta $venta */
            $venta = $this->route('venta');
            $aCobrar = round(max($venta->aCobrar(), 0), 2);
            $neto = round((float) $this->input('monto') - (float) $this->input('vuelto'), 2);

            if (abs($neto - $aCobrar) > 0.005) {
                $v->errors()->add('monto', sprintf(
                    'El importe recibido menos el vuelto debe ser igual al saldo a cobrar ($%s). Con estos valores da $%s.',
                    number_format($aCobrar, 2, ',', '.'),
                    number_format($neto, 2, ',', '.')
                ));
            }
        });
    }

    /** El vuelto es opcional: vacío o cero equivale a "sin vuelto". */
    private function tieneVuelto(): bool
    {
        return (float) $this->input('vuelto', 0) > 0;
    }

    public function messages(): array
    {
        return [
            'monto.lte' => 'El monto supera el saldo a cobrar.',
            'vuelto.lt' => 'El vuelto no puede ser mayor o igual al importe recibido.',
            'cuenta_vuelto_id.required' => 'Elegí de qué cuenta sale el vuelto.',
        ];
    }
}
