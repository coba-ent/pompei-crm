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
        'stock_ultima_sync_en', 'stock_ultima_sync_resultado', 'lista_precio_id',
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
     * Depósito del que Mercado Libre publica el stock: el configurado si existe y
     * está activo; si no, el por defecto del CRM (Deposito::porDefecto(), única
     * definición compartida con las Ventas — spec 013, research.md R1, tasks.md T006).
     *
     * Lanza si no hay ninguno: sin depósito no se puede calcular qué publicar, y
     * fallar es preferible a mandarle a Mercado Libre un stock inventado. Para
     * pantallas informativas usar depositoEfectivoONulo().
     */
    public function depositoEfectivo(): Deposito
    {
        return $this->depositoEfectivoONulo()
            ?? throw new \RuntimeException('No hay ningún depósito activo en el CRM.');
    }

    /**
     * Igual que depositoEfectivo() pero devuelve null en vez de lanzar. Para
     * pantallas que sólo muestran de qué depósito saldría el stock: un CRM sin
     * depósitos todavía cargados no debe romper la pantalla de configuración.
     */
    public function depositoEfectivoONulo(): ?Deposito
    {
        $deposito = $this->deposito_id ? Deposito::find($this->deposito_id) : null;

        if ($deposito && $deposito->activo) {
            return $deposito;
        }

        return Deposito::porDefecto();
    }

    public function categoriaVenta(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Categoria::class, 'categoria_venta_id');
    }

    /** Lista de Precios que gestiona los precios de las publicaciones vinculadas (spec 016). */
    public function listaPrecio(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ListaPrecio::class, 'lista_precio_id');
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
