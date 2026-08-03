<?php

namespace App\Http\Requests\Mensajeria;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Envío de una respuesta manual a una conversación de Mensajería (spec 032, US2). */
class EnviarRespuestaMercadoLibreRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'message' => 'No se pudo enviar la respuesta, revisá el texto.',
            'errors' => $validator->errors(),
        ], 422));
    }

    public function authorize(): bool
    {
        return $this->user()->tienePermiso('mensajeria.responder');
    }

    public function rules(): array
    {
        return [
            'texto' => ['required', 'string', 'max:2000'],
            'sugerencia_id' => ['nullable', 'integer', 'exists:ml_sugerencias,id'],
        ];
    }
}
