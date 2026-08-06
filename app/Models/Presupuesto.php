<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;

class Presupuesto extends Model
{
    use HasFactory;

    protected $table = 'presupuestos';

    protected $fillable = [
        'nro_presupuesto', 'cliente_id', 'categoria_id', 'lista_precio_id',
        'fecha_emision', 'fecha_validez', 'servicio_desde', 'servicio_hasta',
        'estado', 'venta_id', 'descuento_general_pct',
        'subtotal_sin_descuento', 'descuento', 'subtotal_con_descuento', 'total',
        'nota_cliente', 'nota_interna', 'formas_pago', 'metodos_envio',
        'vendedor_id', 'creado_por_id', 'submit_token',
    ];

    protected $casts = [
        'fecha_emision' => 'date',
        'fecha_validez' => 'date',
        'servicio_desde' => 'date',
        'servicio_hasta' => 'date',
        'descuento_general_pct' => 'decimal:2',
        'subtotal_sin_descuento' => 'decimal:2',
        'descuento' => 'decimal:2',
        'subtotal_con_descuento' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function listaPrecio(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Vendedor::class, 'vendedor_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_id');
    }

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PresupuestoItem::class);
    }

    public function conceptos(): HasMany
    {
        return $this->hasMany(PresupuestoConcepto::class);
    }

    public function etiquetas(): MorphToMany
    {
        return $this->morphToMany(Etiqueta::class, 'etiquetable');
    }

    /** ¿Ya fue convertido en venta? No reconvertible (FR-009). */
    public function convertido(): bool
    {
        return $this->venta_id !== null;
    }

    /** "Vencido" = validez pasada + sigue pendiente (derivado, FR-005 — no es un valor de `estado`). */
    public function vencido(): bool
    {
        return $this->estado === 'pendiente'
            && $this->fecha_validez !== null
            && $this->fecha_validez->lt(Carbon::today());
    }

    /** Estado visual para el badge del listado: 'vencido' pisa a 'pendiente' cuando corresponde. */
    public function getEstadoVisualAttribute(): string
    {
        return $this->vencido() ? 'vencido' : $this->estado;
    }

    /** Próximo número correlativo simple (autogenerado, sin significado fiscal). Formato Contagram: 8 dígitos con ceros a la izquierda. */
    public static function siguienteNumero(): string
    {
        return str_pad((string) ((int) static::max('id') + 1), 8, '0', STR_PAD_LEFT);
    }
}
