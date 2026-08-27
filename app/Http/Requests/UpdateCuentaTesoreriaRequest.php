<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Edición de cuenta de tesorería (US2): FR-003/FR-004 (sin `tipo`, inmutable) / FR-006.
 *
 * **Editar una cuenta cambia su nombre y su visibilidad, nada más.** El saldo inicial
 * y su fecha son datos de APERTURA: se declaran al crear la cuenta y no se retocan
 * después, porque reescribirlos mueve plata de un saldo ya conciliado.
 *
 * No es una restricción teórica. Antes de este cambio el formulario mandaba
 * `saldo_inicial` leído de la columna `cuentas_tesoreria.saldo_inicial`, que en la
 * base heredada de Contagram está DESINCRONIZADA del movimiento real: la columna dice
 * 0,00 en cuentas cuyo movimiento de Saldo Inicial vale -1.000.000 (Mercado Pago,
 * Visa a Cobrar), +348.517,30 (Juan USD Personal) y varias más. Como el controlador
 * reescribe ese movimiento con lo recibido, entrar a una de esas cuentas sólo para
 * renombrarla y apretar Guardar le borraba el saldo inicial y le cambiaba el saldo
 * a la cuenta, en silencio. Al no aceptar los campos acá, esa vía deja de existir.
 */
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
