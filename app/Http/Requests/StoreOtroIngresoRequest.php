<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreOtroIngresoRequest extends FormRequest
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
            'categoria_id' => 'required|exists:categorias,id',
            'cuenta_tesoreria_id' => 'required_unless:pendiente,1,true|nullable|exists:cuentas_tesoreria,id',
            'descripcion' => 'nullable|string',
            'pendiente' => 'boolean',
        ];
    }
}
