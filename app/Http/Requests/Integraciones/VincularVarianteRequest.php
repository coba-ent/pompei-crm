<?php

namespace App\Http\Requests\Integraciones;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Alta/edición de la vinculación variante↔producto (FR-021..FR-026). La
 * cardinalidad 1:1 se valida acá con mensaje amable, pero la garantía real
 * son los dos índices únicos de la migración (data-model.md §`tn_variante_producto`).
 */
class VincularVarianteRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'No se pudo guardar la vinculación, revisá los datos.',
            'errors' => $validator->errors(),
        ], 422));
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $vinculacionId = $this->route('vinculacion')?->id;

        return [
            'variant_id' => [
                'required', 'integer',
                Rule::unique('tn_variante_producto', 'variant_id')->ignore($vinculacionId),
            ],
            // Requerido junto con variant_id (spec 018, research.md R6): la tool
            // update_stock_and_price exige el product_id del producto dueño de la
            // variante, no alcanza con el variant_id solo.
            'tn_product_id' => ['required', 'string', 'max:50'],
            'producto_id' => [
                'required', 'exists:productos,id',
                Rule::unique('tn_variante_producto', 'producto_id')->ignore($vinculacionId),
            ],
            'nombre_variante_tn' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'variant_id.unique' => 'Esta variante ya está vinculada a otro producto.',
            'producto_id.unique' => 'Este producto ya está vinculado a otra variante.',
        ];
    }
}
