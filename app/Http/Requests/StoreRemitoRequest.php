<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreRemitoRequest extends FormRequest
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
            'transportista_id' => 'nullable|exists:transportistas,id',
            'domicilio_entrega' => 'nullable|string|max:255',
            'nota' => 'nullable|string',
            'monto_asegurado' => 'nullable|numeric|gte:0',
            'tipo' => 'nullable|string|max:1',
            // FR-009: al menos una línea, con cantidades > 0.
            'items' => 'required|array|min:1',
            'items.*.producto_id' => 'nullable|integer',
            'items.*.codigo' => 'nullable|string|max:255',
            'items.*.descripcion' => 'required|string|max:255',
            'items.*.observacion' => 'nullable|string|max:255',
            'items.*.cantidad' => 'required|numeric|gt:0',
        ];
    }
}
