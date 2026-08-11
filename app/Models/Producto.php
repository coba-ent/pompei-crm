<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    use HasFactory;

    protected $table = 'productos';

    /**
     * Opciones de "IVA por defecto" tal como las ofrece Contagram en el modal
     * de producto (select fijo). La clave es el código que se persiste; 'label'
     * es la etiqueta visible y 'pct' el porcentaje numérico para los cálculos.
     * "Exento" y "No Gravado" no son porcentajes: computan 0% pero se conservan
     * como opción distinta para no perder la condición fiscal del producto.
     */
    public const OPCIONES_IVA = [
        '5' => ['label' => '5', 'pct' => 5.0],
        '10.5' => ['label' => '10,5', 'pct' => 10.5],
        '21' => ['label' => '21', 'pct' => 21.0],
        '27' => ['label' => '27', 'pct' => 27.0],
        'exento' => ['label' => 'Exento', 'pct' => 0.0],
        'no_gravado' => ['label' => 'No Gravado', 'pct' => 0.0],
    ];

    protected $fillable = [
        'legacy_id',
        'nombre',
        'codigo',
        'tipo',
        'tipo_producto_id',
        'proveedor_id',
        'descripcion',
        'imagen',
        'mostrar_en_ventas',
        'precio_venta',
        'iva_venta_pct',
        'mostrar_en_compras',
        'costo',
        'iva_compra_pct',
        'activo',
    ];

    protected $appends = ['imagen_url'];

    protected $casts = [
        'mostrar_en_ventas' => 'boolean',
        'mostrar_en_compras' => 'boolean',
        'precio_venta' => 'decimal:2',
        'costo' => 'decimal:2',
        'activo' => 'boolean',
    ];

    /** Porcentaje numérico de una opción de IVA ('exento'/'no_gravado' → 0). */
    public static function porcentajeIva(?string $opcion): float
    {
        return self::OPCIONES_IVA[$opcion]['pct'] ?? 0.0;
    }

    /** Etiqueta visible de una opción de IVA (ej. '10.5' → '10,5'). */
    public static function etiquetaIva(?string $opcion): string
    {
        return self::OPCIONES_IVA[$opcion]['label'] ?? (string) $opcion;
    }

    /** Porcentaje de IVA de ventas aplicable a este producto. */
    public function ivaVentaPorcentaje(): float
    {
        return self::porcentajeIva($this->iva_venta_pct);
    }

    /** Porcentaje de IVA de compras aplicable a este producto. */
    public function ivaCompraPorcentaje(): float
    {
        return self::porcentajeIva($this->iva_compra_pct);
    }

    public function variantes(): HasMany
    {
        return $this->hasMany(ProductoVariante::class, 'producto_id');
    }

    public function precios(): HasMany
    {
        return $this->hasMany(PrecioProducto::class, 'producto_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class, 'producto_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoStock::class, 'producto_id');
    }

    /** "Tipo de Producto" (catálogo: Compra y Venta, Consignación, etc.). */
    public function tipoProducto(): BelongsTo
    {
        return $this->belongsTo(TipoProducto::class, 'tipo_producto_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    /** URL pública de la imagen del producto (o null si no tiene). */
    public function getImagenUrlAttribute(): ?string
    {
        return $this->imagen ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->imagen) : null;
    }

    public function esServicio(): bool
    {
        return $this->tipo === 'servicio';
    }

    /** Un servicio nunca controla stock (SC-007). */
    public function controlaStock(): bool
    {
        return ! $this->esServicio();
    }

    /**
     * ¿El producto (o alguna de sus variantes) tiene operaciones asociadas?
     * Hoy: existe algún movimiento de stock. Costura para ventas/compras
     * futuras. Bloquea la eliminación física cuando es true (FR-020).
     */
    public function tieneOperaciones(): bool
    {
        return $this->movimientos()->exists();
    }

    /** Suma de stock del producto (y sus variantes) en todos los depósitos. */
    public function stockTotal(): float
    {
        return (float) $this->stocks()->sum('cantidad');
    }
}
