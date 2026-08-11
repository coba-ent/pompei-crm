<?php

namespace App\Http\Requests;

use App\Models\Compra;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use App\Services\AjustesPendientesNotaCreditoDebito;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateNotaCreditoDebitoRequest extends FormRequest
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
            'items.*.descuento_pct' => 'nullable|numeric|between:0,100',
            'items.*.iva_pct' => 'nullable|numeric|gte:0',
            'descripcion' => 'required_unless:afecta_stock,1,true|nullable|string',
            'fecha_emision' => 'required|date',
            'tipo_comprobante' => 'nullable|string',
            'nro_comprobante' => 'nullable|string',
            'monto' => 'required|numeric|gt:0',
            'documento_ajusta' => 'nullable|array',
            'documento_ajusta.tipo' => 'nullable|in:comprobante_original,nota',
            'documento_ajusta.nota_ajustada_id' => 'nullable|integer|exists:notas_credito_debito,id',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        /** @var NotaCreditoDebito $nota */
        $nota = $this->route('notaCreditoDebito');

        $validator->after(function (Validator $validator) use ($nota) {
            // FR-002: el tipo (Crédito/Débito) no es editable una vez creada la nota.
            if ($this->filled('tipo') && $this->input('tipo') !== $nota->tipo) {
                $validator->errors()->add('tipo', 'El tipo de la nota (Crédito/Débito) no se puede modificar.');
            }

            // FR-012: nro_comprobante+tipo_comprobante único contra Venta/Compra/NotaCreditoDebito (excluyendo esta misma nota).
            if ($this->filled('nro_comprobante') && $this->filled('tipo_comprobante')) {
                $tipoComprobante = $this->input('tipo_comprobante');
                $nroComprobante = $this->input('nro_comprobante');

                $duplicado = Venta::where('tipo_comprobante', $tipoComprobante)->where('nro_comprobante', $nroComprobante)->exists()
                    || Compra::where('tipo_comprobante', $tipoComprobante)->where('nro_comprobante', $nroComprobante)->exists()
                    || NotaCreditoDebito::where('tipo_comprobante', $tipoComprobante)
                        ->where('nro_comprobante', $nroComprobante)
                        ->where('id', '!=', $nota->id)
                        ->exists();

                if ($duplicado) {
                    $validator->errors()->add('nro_comprobante', "Ya existe un comprobante {$tipoComprobante}-{$nroComprobante} en Ventas/Compras/Notas.");
                }
            }

            // FR-013: sólo se puede encadenar a una nota de "nivel 0" (que no ajuste a su vez a otra).
            if (($this->input('documento_ajusta.tipo') ?? null) === 'nota' && $this->filled('documento_ajusta.nota_ajustada_id')) {
                $notaAjustada = NotaCreditoDebito::find($this->input('documento_ajusta.nota_ajustada_id'));

                if ($notaAjustada && $notaAjustada->nota_ajustada_id) {
                    $validator->errors()->add('documento_ajusta.nota_ajustada_id', 'No se puede ajustar una nota que ya ajusta a otra.');
                }
            }

            // FR-005: "pendiente" excluyendo la nota en edición del cálculo de lo ya ajustado.
            if (! $this->boolean('afecta_stock') || ! is_array($this->input('items'))) {
                return;
            }

            $comprobante = $nota->venta ?? $nota->compra;
            if (! $comprobante instanceof Venta && ! $comprobante instanceof Compra) {
                return;
            }

            $helper = new AjustesPendientesNotaCreditoDebito();

            foreach ($this->input('items') as $i => $item) {
                if (empty($item['producto_id']) || ! isset($item['cantidad'])) {
                    continue;
                }

                $pendiente = $helper->pendiente($comprobante, (int) $item['producto_id'], $nota);

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
