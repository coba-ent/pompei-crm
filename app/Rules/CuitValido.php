<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida un CUIT/CUIL argentino: 11 dígitos, prefijo válido y dígito
 * verificador calculado con el algoritmo módulo 11 estándar de ARCA.
 * Validación puramente local (no requiere red).
 */
class CuitValido implements ValidationRule
{
    /** Prefijos válidos de CUIT/CUIL. */
    private const PREFIJOS = ['20', '23', '24', '27', '30', '33', '34'];

    /** Multiplicadores del algoritmo módulo 11 sobre los primeros 10 dígitos. */
    private const MULTIPLICADORES = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::esValido((string) $value)) {
            $fail('El CUIT ingresado no es válido.');
        }
    }

    public static function esValido(string $cuit): bool
    {
        // Sólo dígitos, exactamente 11.
        if (! preg_match('/^\d{11}$/', $cuit)) {
            return false;
        }

        if (! in_array(substr($cuit, 0, 2), self::PREFIJOS, true)) {
            return false;
        }

        $suma = 0;
        for ($i = 0; $i < 10; $i++) {
            $suma += (int) $cuit[$i] * self::MULTIPLICADORES[$i];
        }

        $resto = $suma % 11;
        $verificador = 11 - $resto;

        if ($verificador === 11) {
            $verificador = 0;
        } elseif ($verificador === 10) {
            $verificador = 9;
        }

        return $verificador === (int) $cuit[10];
    }
}
