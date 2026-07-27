<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Transferencia de stock entre dos depósitos (Ajuste de Stock → Movimiento
 * entre Depósitos). El producto llega por route-model-binding.
 */
class TransferenciaStockRequest extends FormRequest
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
        $producto = $this->route('producto');
        $tieneVariantes = $producto && $producto->variantes()->exists();

        return [
            'deposito_salida_id' => ['required', 'exists:depositos,id'],
            'deposito_entrada_id' => ['required', 'exists:depositos,id', 'different:deposito_salida_id'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'fecha' => ['nullable', 'date'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'variante_id' => [
                $tieneVariantes ? 'required' : 'nullable',
                Rule::exists('producto_variantes', 'id')->where('producto_id', $producto?->id),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $producto = $this->route('producto');
            if ($producto && $producto->esServicio()) {
                $validator->errors()->add('producto', 'Un servicio no controla stock.');
            }
        });
    }
}
