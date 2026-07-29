<?php

namespace App\Models\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro único (single-tenant) con las credenciales de la Aplicación
 * personalizada, los datos de la tienda vinculada y el estado de la conexión.
 * Acceder siempre por actual(), nunca con find() suelto — ver data-model.md §1.
 */
class TiendanubeConfiguracion extends Model
{
    protected $table = 'tn_configuracion';

    protected $fillable = [
        'store_id', 'access_token', 'nombre_tienda', 'dominio', 'pais', 'moneda',
        'estado', 'ultimo_error', 'modo_solo_lectura', 'credenciales_guardadas_en',
        'ultima_verificacion_en', 'actualizada_por',
    ];

    protected $hidden = ['access_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'estado' => EstadoConexion::class,
        'modo_solo_lectura' => 'boolean',
        'credenciales_guardadas_en' => 'datetime',
        'ultima_verificacion_en' => 'datetime',
    ];

    public static function actual(): self
    {
        $configuracion = static::query()->first();

        if (! $configuracion) {
            $configuracion = new static();
            $configuracion->id = 1;
            $configuracion->estado = EstadoConexion::Desconectada;
            $configuracion->save();
        }

        return $configuracion;
    }

    /**
     * Chequeo de presencia, no de legibilidad: usa el valor crudo (todavía
     * cifrado) para no disparar un DecryptException acá — ese caso lo maneja
     * ClienteTiendanube al intentar usar el token de verdad (edge case spec.md).
     */
    public function estaCompleta(): bool
    {
        return filled($this->store_id) && filled($this->getRawOriginal('access_token'));
    }

    public function actualizadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizada_por');
    }
}
