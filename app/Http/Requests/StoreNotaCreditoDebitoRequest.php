<?php

namespace App\Http\Requests;

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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => 'required|in:credito,debito',
            'afecta_stock' => 'boolean',
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
}
