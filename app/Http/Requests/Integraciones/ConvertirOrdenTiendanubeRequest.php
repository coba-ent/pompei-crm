<?php

namespace App\Http\Requests\Integraciones;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Conversión de una orden de Tiendanube en Venta (contracts §1, US3). Cantidad
 * y precio de cada línea NO se aceptan del cliente: siempre se recalculan a
 * partir de la orden (FR-030). Sólo son corregibles el Cliente, el tipo de
 * comprobante (FR-043) y la vinculación inline de líneas sin producto (FR-023).
 */
class ConvertirOrdenTiendanubeRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'No se pudo convertir la orden, revisá los datos.',
            'errors' => $validator->errors(),
        ], 422));
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'submit_token' => ['required', 'string'],
            'cliente_id' => ['nullable', 'exists:clientes,id'],
            'tipo_comprobante' => ['nullable', 'in:A,B,C,E'],
            'vinculaciones_inline' => ['nullable', 'array'],
            'vinculaciones_inline.*.variant_id' => ['required_with:vinculaciones_inline', 'integer'],
            'vinculaciones_inline.*.producto_id' => ['required_with:vinculaciones_inline', 'exists:productos,id'],
        ];
    }
}
