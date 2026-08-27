<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reordenamiento de cuentas de tesorería dentro de un bloque (spec 085).
 *
 * Sólo valida la **forma** del payload. La validación de conjunto —que los ids
 * recibidos sean exactamente los del tipo— vive en el controlador porque su
 * rechazo es un 409 (conflicto de concurrencia), no un 422 de formulario.
 */
class ReordenarCuentasRequest extends FormRequest
{
    /** El permiso lo aplica el middleware `permiso:tesoreria.editar` de la ruta. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => 'required|string|in:efectivo,banco,a_cobrar,a_pagar',
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|distinct|exists:cuentas_tesoreria,id',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Falta indicar el tipo de cuenta.',
            'tipo.in' => 'El tipo de cuenta no es válido.',
            'ids.required' => 'El listado de cuentas no es válido.',
            'ids.array' => 'El listado de cuentas no es válido.',
            'ids.min' => 'El listado de cuentas no es válido.',
            'ids.*.required' => 'El listado de cuentas no es válido.',
            'ids.*.integer' => 'El listado de cuentas no es válido.',
            'ids.*.distinct' => 'El listado de cuentas tiene una cuenta repetida.',
            'ids.*.exists' => 'Una de las cuentas del listado ya no existe.',
        ];
    }
}
