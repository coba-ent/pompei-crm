<?php

namespace App\Http\Requests\Integraciones;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Services\MercadoLibre\Sitios;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class GuardarConfiguracionMercadoLibreRequest extends FormRequest
{
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
        // client_secret es opcional al editar (si viene vacío se conserva el guardado);
        // requerido en el alta, cuando todavía no hay ningún secreto persistido.
        $yaHaySecretoGuardado = filled(MercadoLibreConfiguracion::actual()->client_secret);

        return [
            'client_id' => ['required', 'digits_between:8,32'],
            'client_secret' => [
                $yaHaySecretoGuardado ? 'nullable' : 'required',
                'string',
                'min:16',
                'max:128',
            ],
            'site_id' => ['required', Rule::in(Sitios::claves())],
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'Ingresá el App ID de la aplicación.',
            'client_id.digits_between' => 'El App ID debe tener entre 8 y 32 dígitos numéricos.',
            'client_secret.required' => 'Ingresá la clave secreta de la aplicación.',
            'client_secret.min' => 'La clave secreta debe tener al menos 16 caracteres.',
            'client_secret.max' => 'La clave secreta no puede superar los 128 caracteres.',
            'site_id.required' => 'Elegí el sitio de operación.',
            'site_id.in' => 'El sitio elegido no está soportado.',
        ];
    }
}
