<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Deposito extends Model
{
    protected $table = 'depositos';

    protected $fillable = ['nombre', 'activo'];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * ÚNICA definición de "depósito por defecto del CRM": el primero activo por
     * orden de alta. El modelo de Depósitos (spec 005) no tiene un flag explícito,
     * así que se usa el id — determinístico y estable mientras no exista esa columna.
     *
     * Es el depósito del que descuentan las Ventas y, salvo que se configure otro,
     * el que se publica en Mercado Libre. Los selects de la interfaz DEBEN
     * preseleccionar éste: si la interfaz ofrece un default distinto (por ejemplo
     * el primero alfabético), se cargan movimientos en un depósito que la
     * integración no mira, y el stock publicado queda desfasado sin ningún aviso
     * (pasó el 28/07/2026 — ver MERCADOLIBRE_NOTAS_TECNICAS.md §10).
     */
    public static function porDefecto(): ?self
    {
        return static::activos()->orderBy('id')->first();
    }

    /**
     * ¿El depósito tiene stock o movimientos asociados? Bloquea la eliminación
     * física (FR-005), mismo patrón que Cliente/Proveedor/Producto::tieneOperaciones().
     */
    public function tieneOperaciones(): bool
    {
        return $this->stocks()->where('cantidad', '!=', 0)->exists()
            || $this->movimientos()->exists();
    }

    public function stocks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Stock::class, 'deposito_id');
    }

    public function movimientos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MovimientoStock::class, 'deposito_id');
    }
}
