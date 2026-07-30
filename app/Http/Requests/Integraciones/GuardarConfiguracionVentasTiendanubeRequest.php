<?php

namespace App\Http\Requests\Integraciones;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Configuración de ventas de Tiendanube (contracts §3, FR-010/FR-016/FR-045/FR-047/FR-050).
 * `cuenta_tesoreria_id` es nullable acá (guardar la configuración sin ella es
 * válido) pero bloquea la conversión mientras falte (FR-045a) — distinto de
 * `deposito_id`/`categoria_venta_id`, que sí toleran ausencia permanente.
 */
class GuardarConfiguracionVentasTiendanubeRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'Los datos ingresados no son válidos.',
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
            'creacion_automatica' => ['required', 'boolean'],
            'frecuencia_sync_minutos' => ['required', Rule::in([5, 10, 15, 30, 60])],
            'deposito_id' => ['nullable', 'exists:depositos,id'],
            'categoria_venta_id' => ['nullable', 'exists:categorias,id'],
            'cuenta_tesoreria_id' => ['nullable', 'exists:cuentas_tesoreria,id'],
            'dias_primera_sync' => ['required', 'integer', 'min:1', 'max:365'],
            'lista_precio_id' => ['nullable', 'exists:listas_precio,id'],
            'vendedor_id' => ['nullable', 'integer', 'exists:vendedores,id'],
        ];
    }
}
