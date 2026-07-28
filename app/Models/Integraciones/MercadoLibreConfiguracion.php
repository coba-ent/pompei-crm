<?php

namespace App\Models\Integraciones;

use App\Models\Deposito;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro único (single-tenant) con los datos de la aplicación del DevCenter.
 * Acceder siempre por actual(), nunca con find() suelto — ver data-model.md §2.
 */
class MercadoLibreConfiguracion extends Model
{
    protected $table = 'ml_configuracion';

    protected $fillable = [
        'client_id', 'client_secret', 'site_id', 'modo_solo_lectura', 'actualizada_por',
        'creacion_automatica', 'frecuencia_sync_minutos', 'deposito_id', 'categoria_venta_id',
        'dias_primera_sync', 'ultima_sync_en', 'ultima_sync_resultado',
        'stock_ultima_sync_en', 'stock_ultima_sync_resultado',
    ];

    protected $hidden = ['client_secret'];

    protected $casts = [
        'client_secret' => 'encrypted',
        'modo_solo_lectura' => 'boolean',
        'creacion_automatica' => 'boolean',
        'ultima_sync_en' => 'datetime',
        'stock_ultima_sync_en' => 'datetime',
    ];

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Deposito::class, 'deposito_id');
    }

    /**
     * Depósito configurado para Mercado Libre si existe y está activo; si no,
     * el primero activo por orden de alta — misma regla que
     * StockDeVenta::depositoPorDefecto() (spec 013, research.md R1, tasks.md T006).
     */
    public function depositoEfectivo(): Deposito
    {
        $deposito = $this->deposito_id ? Deposito::find($this->deposito_id) : null;

        if ($deposito && $deposito->activo) {
            return $deposito;
        }

        return Deposito::activos()->orderBy('id')->firstOrFail();
    }

    public function categoriaVenta(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Categoria::class, 'categoria_venta_id');
    }

    public static function actual(): self
    {
        $configuracion = static::query()->first();

        if (! $configuracion) {
            $configuracion = new static();
            $configuracion->id = 1;
            $configuracion->save();
        }

        return $configuracion;
    }

    public function estaCompleta(): bool
    {
        return filled($this->client_id) && filled($this->client_secret);
    }

    public function actualizadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizada_por');
    }
}
