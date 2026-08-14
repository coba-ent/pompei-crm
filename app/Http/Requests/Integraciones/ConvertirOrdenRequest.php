<?php

namespace App\Http\Requests\Integraciones;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Conversión de una orden en Venta (contracts §1, US3). Cantidad y precio de
 * cada línea NO se aceptan del cliente: siempre se recalculan a partir de la
 * orden (FR-030) para que la coincidencia exacta del total nunca dependa de
 * lo que el navegador envíe. Sólo son corregibles el Cliente, el tipo de
 * comprobante (FR-043) y la vinculación inline de líneas sin producto (FR-023).
 */
class ConvertirOrdenRequest extends FormRequest
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
            // spec 066 (FR-010): confirmación explícita para convertir una orden en estado
            // excepcional. Que llegue no alcanza — el controlador igual verifica el estado
            // contra la base, porque este endpoint se puede llamar sin pasar por la interfaz.
            'forzar_conversion' => ['nullable', 'boolean'],
            'vinculaciones_inline' => ['nullable', 'array'],
            'vinculaciones_inline.*.ml_item_id' => ['required_with:vinculaciones_inline', 'string'],
            'vinculaciones_inline.*.producto_id' => ['required_with:vinculaciones_inline', 'exists:productos,id'],
        ];
    }
}
