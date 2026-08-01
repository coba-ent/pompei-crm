<?php

namespace App\Models\Integraciones;

use App\Enums\Tiendanube\EstadoConexion;
use App\Models\Categoria;
use App\Models\CuentaTesoreria;
use App\Models\Deposito;
use App\Models\ListaPrecio;
use App\Models\User;
use App\Models\Vendedor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro único (single-tenant) de la conexión OAuth clásica contra la
 * Application del Partner Portal (spec 022, aditiva a spec 019) — REST API
 * estándar (`api.tiendanube.com`), no el servidor MCP. Acceder siempre por
 * actual(). Desde spec 024 también contiene la configuración de negocio
 * (depósito, categoría, cuenta, lista de precios, vendedor, modo sólo
 * lectura, ventana de sincronización) migrada desde `TiendanubeConfiguracion`
 * (MCP, retirada en Historia 3 de spec 024) — data-model.md §1.
 */
class TiendanubeConexionRest extends Model
{
    protected $table = 'tn_conexion_rest';

    protected $fillable = [
        'access_token', 'store_id', 'scopes_otorgados', 'tienda_nombre', 'tienda_dominio',
        'estado', 'ultimo_error', 'conectada_en', 'actualizada_por',
        'modo_solo_lectura', 'creacion_automatica', 'frecuencia_sync_minutos', 'deposito_id',
        'categoria_venta_id', 'cuenta_tesoreria_id', 'dias_primera_sync', 'ultima_sync_en',
        'ultima_sync_resultado', 'stock_ultima_sync_en', 'stock_ultima_sync_resultado',
        'lista_precio_id', 'vendedor_id',
    ];

    protected $hidden = ['access_token'];

    protected $casts = [
        'access_token' => 'encrypted',
        'estado' => EstadoConexion::class,
        'conectada_en' => 'datetime',
        'modo_solo_lectura' => 'boolean',
        'creacion_automatica' => 'boolean',
        'ultima_sync_en' => 'datetime',
        'stock_ultima_sync_en' => 'datetime',
    ];

    public static function actual(): self
    {
        $conexion = static::query()->first();

        if (! $conexion) {
            $conexion = new static();
            $conexion->id = 1;
            $conexion->estado = EstadoConexion::NoConfigurada;
            $conexion->save();
        }

        return $conexion;
    }

    public function estaCompleta(): bool
    {
        return filled($this->getRawOriginal('access_token')) && filled($this->store_id);
    }

    public function actualizadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizada_por');
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'deposito_id');
    }

    public function categoriaVenta(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_venta_id');
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Vendedor::class, 'vendedor_id');
    }

    public function cuentaTesoreria(): BelongsTo
    {
        return $this->belongsTo(CuentaTesoreria::class, 'cuenta_tesoreria_id');
    }

    public function listaPrecio(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class, 'lista_precio_id');
    }

    /**
     * Depósito del que se descuenta el stock de las Ventas de Tiendanube: el
     * configurado si existe y está activo; si no, el por defecto del CRM
     * (calcado de `TiendanubeConfiguracion::depositoEfectivo()`, spec 024).
     */
    public function depositoEfectivo(): Deposito
    {
        return $this->depositoEfectivoONulo()
            ?? throw new \RuntimeException('No hay ningún depósito activo en el CRM.');
    }

    /** Igual que depositoEfectivo() pero devuelve null en vez de lanzar — para pantallas informativas. */
    public function depositoEfectivoONulo(): ?Deposito
    {
        $deposito = $this->deposito_id ? Deposito::find($this->deposito_id) : null;

        if ($deposito && $deposito->activo) {
            return $deposito;
        }

        return Deposito::porDefecto();
    }
}
