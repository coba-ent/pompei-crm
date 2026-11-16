<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CondicionIva extends Model
{
    protected $table = 'condiciones_iva';

    protected $fillable = ['nombre', 'codigo_afip', 'requiere_cuit'];

    protected $casts = [
        'requiere_cuit' => 'boolean',
    ];

    /**
     * Condiciones frente al IVA (códigos de ARCA) a las que se les emite Factura A: Responsable
     * Inscripto (1), Monotributista (6), Monotributista Social (13) y Monotributo Trabajador
     * Independiente Promovido (16). Es la tabla de `FEParamGetCondicionIvaReceptor`: con una B a
     * un monotributista ARCA rechaza con el error 10243 (pasó en producción el 16/11/2026 con
     * ventas de Mercado Libre). Antes el CRM le asignaba B a todo lo que no fuera RI.
     */
    public const CODIGOS_FACTURA_A = ['1', '6', '13', '16'];

    /** Nombres de `condiciones_iva` que reciben Factura A (mismo criterio que CODIGOS_FACTURA_A). */
    public const NOMBRES_FACTURA_A = ['Responsable Inscripto', 'Monotributista'];

    public static function codigoRecibeFacturaA(?string $codigoAfip): bool
    {
        return in_array((string) $codigoAfip, self::CODIGOS_FACTURA_A, true);
    }

    public static function nombreRecibeFacturaA(?string $nombre): bool
    {
        return in_array((string) $nombre, self::NOMBRES_FACTURA_A, true);
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class);
    }
}
