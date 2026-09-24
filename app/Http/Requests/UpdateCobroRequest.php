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
     * Igual que en el alta, `monto` es el importe RECIBIDO y a la venta se le imputa el neto
     * (spec 110). El tope suma el neto del propio cobro porque al editarlo se lo "devuelve" antes
     * de recalcular.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cuenta_tesoreria_id' => 'required|exists:cuentas_tesoreria,id',
            'monto' => ['required', 'numeric', 'gt:0', ...($this->tieneVuelto() ? [] : ['lte:'.$this->topeSinVuelto()])],
            'vuelto' => ['nullable', 'numeric', 'gte:0', 'lt:monto'],
            'cuenta_vuelto_id' => [
                $this->tieneVuelto() ? 'required' : 'nullable',
                'nullable', 'exists:cuentas_tesoreria,id',
            ],
            'fecha' => 'required|date',
            'nota' => 'nullable|string',
        ];
    }

    /** FR-007: con vuelto, el neto tiene que saldar exactamente lo que queda de la venta. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (! $this->tieneVuelto() || $v->errors()->hasAny(['monto', 'vuelto'])) {
                return;
            }

            $tope = round($this->topeSinVuelto(), 2);
            $neto = round((float) $this->input('monto') - (float) $this->input('vuelto'), 2);

            if (abs($neto - $tope) > 0.005) {
                $v->errors()->add('monto', sprintf(
                    'El importe recibido menos el vuelto debe ser igual al saldo a cobrar ($%s). Con estos valores da $%s.',
                    number_format($tope, 2, ',', '.'),
                    number_format($neto, 2, ',', '.')
                ));
            }
        });
    }

    /**
     * Saldo disponible para este cobro: lo que queda por cobrar más el neto que el propio cobro ya
     * tenía imputado (se lo devuelve antes de recalcular).
     */
    private function topeSinVuelto(): float
    {
        /** @var Venta $venta */
        $venta = $this->route('venta');
        /** @var Cobro $cobro */
        $cobro = $this->route('cobro');

        return max($venta->aCobrar() + (float) $cobro->monto, 0);
    }

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
