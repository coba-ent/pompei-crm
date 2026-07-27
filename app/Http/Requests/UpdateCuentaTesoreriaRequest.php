<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Edición de cuenta de tesorería (US2): FR-003/FR-004 (sin `tipo`, inmutable) / FR-006. */
class UpdateCuentaTesoreriaRequest extends FormRequest
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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'saldo_inicial' => ['nullable', 'numeric'],
            'saldo_inicial_fecha' => ['required', 'date'],
            'visible' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $cuenta = $this->route('cuenta');
            if ($cuenta && $cuenta->es_sistema) {
                $validator->errors()->add('es_sistema', 'La cuenta es del sistema y no puede editarse.');
            }
        });
    }
}
