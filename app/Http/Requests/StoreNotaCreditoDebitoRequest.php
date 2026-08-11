<?php

namespace App\Http\Requests;

use App\Models\Compra;
use App\Models\Venta;
use App\Services\AjustesPendientesNotaCreditoDebito;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreNotaCreditoDebitoRequest extends FormRequest
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

    protected function prepareForValidation(): void
    {
        if ($this->filled('mes_imputacion')) {
            $this->merge([
                'mes_imputacion' => \Illuminate\Support\Carbon::parse($this->input('mes_imputacion'))->startOfMonth()->toDateString(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => 'required|in:credito,debito',
            'afecta_stock' => 'boolean',
            'mes_imputacion' => 'required|date',
            'deposito_id' => 'required_if:afecta_stock,1,true|nullable|exists:depositos,id',
            'items' => 'required_if:afecta_stock,1,true|array',
            'items.*.producto_id' => 'required_with:items|exists:productos,id',
            'items.*.cantidad' => 'required_with:items|numeric|gt:0',
            'items.*.precio' => 'nullable|numeric|gte:0',
            'descripcion' => 'required_unless:afecta_stock,1,true|nullable|string',
            'fecha_emision' => 'required|date',
            'monto' => 'required|numeric|gt:0',
            'descuento_general_tipo' => 'nullable|in:porcentaje,monto',
            'descuento_general_pct' => 'nullable|numeric|between:0,100',
            'descuento_general_monto' => 'nullable|numeric|min:0',
            'conceptos' => 'nullable|array',
            'conceptos.*.tipo' => 'required_with:conceptos|in:percepcion,impuesto_interno,interes',
            'conceptos.*.concepto' => 'required_with:conceptos|string|max:255',
            'conceptos.*.monto' => 'required_with:conceptos|numeric',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // FR-007: el descuento general en modo monto fijo no puede superar el subtotal
            // bruto de los ítems (NC/ND no recalcula server-side — research.md §R4 — así que
            // sólo se valida el tope cuando hay items con los que armar ese subtotal).
            if ($this->input('descuento_general_tipo') === 'monto' && is_array($this->input('items'))) {
                $montoDescuento = (float) $this->input('descuento_general_monto', 0);
                $subtotalBruto = 0.0;

                foreach ($this->input('items') as $item) {
                    $bruto = (float) ($item['cantidad'] ?? 0) * (float) ($item['precio'] ?? 0);
                    $subtotalBruto += $bruto;
                }

                if ($montoDescuento > $subtotalBruto) {
                    $validator->errors()->add('descuento_general_monto', 'El descuento general no puede ser mayor al subtotal del comprobante.');
                }
            }

            if (! $this->boolean('afecta_stock') || ! is_array($this->input('items'))) {
                return;
            }

            $comprobante = $this->route('venta') ?? $this->route('compra');
            if (! $comprobante instanceof Venta && ! $comprobante instanceof Compra) {
                return;
            }

            $helper = new AjustesPendientesNotaCreditoDebito();

            foreach ($this->input('items') as $i => $item) {
                if (empty($item['producto_id']) || ! isset($item['cantidad'])) {
                    continue;
                }

                $pendiente = $helper->pendiente($comprobante, (int) $item['producto_id']);

                if ((float) $item['cantidad'] > $pendiente) {
                    $validator->errors()->add(
                        "items.{$i}.cantidad",
                        "La cantidad máxima disponible para ajustar es {$pendiente}."
                    );
                }
            }
        });
    }
}
