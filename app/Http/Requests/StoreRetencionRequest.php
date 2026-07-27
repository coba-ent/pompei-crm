<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Retención sobre un Pago de Compra (US2) — reutilizable para Cobro si se necesitara desde Ventas. */
class StoreRetencionRequest extends FormRequest
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
        return [
            'fecha' => 'required|date',
            'monto' => 'required|numeric|gt:0',
            'tipo_retencion' => 'required|string|max:255',
            'nro_comprobante' => 'nullable|string|max:255',
            'descripcion' => 'nullable|string',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null)
    {
        $datos = parent::validated($key, $default);
        $datos['pago_id'] = $this->route('compra')->pagos()->latest()->value('id');

        return $datos;
    }
}
