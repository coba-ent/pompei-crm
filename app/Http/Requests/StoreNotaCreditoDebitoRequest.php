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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
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
