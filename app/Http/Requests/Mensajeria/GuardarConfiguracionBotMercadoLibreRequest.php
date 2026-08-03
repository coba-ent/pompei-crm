<?php

namespace App\Http\Requests\Mensajeria;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Guardar la configuración del bot de sugerencias de IA (spec 033, US1, FR-002/FR-003). */
class GuardarConfiguracionBotMercadoLibreRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'No se pudo guardar la configuración.',
            'errors' => $validator->errors(),
        ], 422));
    }

    public function authorize(): bool
    {
        return $this->user()->tienePermiso('configuracion.funciones');
    }

    public function rules(): array
    {
        return [
            'instrucciones_tono' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
