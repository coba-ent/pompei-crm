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
 * Registro único (single-tenant) con las credenciales del cliente OAuth
 * auto-registrado, el token de acceso vigente y el estado de la conexión.
 * Acceder siempre por actual(), nunca con find() suelto — ver data-model.md §1.
 */
class TiendanubeConfiguracion extends Model
{
    protected $table = 'tn_configuracion';

    protected $fillable = [
        'client_id', 'client_secret', 'access_token', 'scopes_otorgados', 'productos_total',
        'conectada_en', 'token_expira_en', 'estado', 'ultimo_error', 'modo_solo_lectura', 'actualizada_por',
        'creacion_automatica', 'frecuencia_sync_minutos', 'deposito_id', 'categoria_venta_id',
        'cuenta_tesoreria_id', 'dias_primera_sync', 'ultima_sync_en', 'ultima_sync_resultado',
        'stock_ultima_sync_en', 'stock_ultima_sync_resultado', 'lista_precio_id', 'vendedor_id',
    ];

    protected $hidden = ['access_token', 'client_secret'];

    protected $casts = [
        'access_token' => 'encrypted',
        'client_secret' => 'encrypted',
        'estado' => EstadoConexion::class,
        'modo_solo_lectura' => 'boolean',
        'productos_total' => 'integer',
        'conectada_en' => 'datetime',
        'token_expira_en' => 'datetime',
        'creacion_automatica' => 'boolean',
        'ultima_sync_en' => 'datetime',
        'stock_ultima_sync_en' => 'datetime',
    ];

    public static function actual(): self
    {
        $configuracion = static::query()->first();

        if (! $configuracion) {
            $configuracion = new static();
            $configuracion->id = 1;
            $configuracion->estado = EstadoConexion::NoConfigurada;
            $configuracion->save();
        }

        return $configuracion;
    }

    /**
     * Chequeo de presencia, no de legibilidad: usa el valor crudo (todavía
     * cifrado) para no disparar un DecryptException acá — ese caso lo maneja
     * ClienteTiendanube al intentar usar el token de verdad (edge case spec.md).
     * Ya no depende de store_id (data-model.md, notas de implementación).
     */
    public function estaCompleta(): bool
    {
        return filled($this->getRawOriginal('access_token'));
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
     * (research.md R4, FR-047) — calcado de
     * `MercadoLibreConfiguracion::depositoEfectivo()`, sin fallback compartido
     * entre integraciones (independencia deliberada, spec 015).
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
