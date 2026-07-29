<?php

namespace App\Http\Requests\Integraciones;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Guarda o reemplaza store_id y/o access_token (contracts/rutas-internas.md).
 * Al menos uno de los dos campos debe venir en el request; el que no venga
 * conserva su valor actual (TiendanubeConfiguracionController::credenciales()).
 */
class GuardarCredencialesTiendanubeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Edge case spec.md: el token (o el store_id) se pega con espacios o
        // caracteres invisibles alrededor — se normaliza antes de validar.
        $normalizar = fn (?string $valor) => $valor === null ? null : trim(preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $valor));

        $this->merge([
            'store_id' => $this->has('store_id') ? $normalizar($this->input('store_id')) : null,
            'access_token' => $this->has('access_token') ? $normalizar($this->input('access_token')) : null,
        ]);
    }

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
            'store_id' => ['nullable', 'digits_between:1,50'],
            'access_token' => ['nullable', 'string', 'min:8', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'store_id.digits_between' => 'El identificador de tienda debe ser numérico.',
            'access_token.min' => 'El token de acceso ingresado no parece válido.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (blank($this->input('store_id')) && blank($this->input('access_token'))) {
                $validator->errors()->add('store_id', 'Ingresá el identificador de tienda o el token de acceso.');
            }
        });
    }
}
