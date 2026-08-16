<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    use HasFactory;

    /**
     * Proveedor FICTICIO usado como ajuste de conciliación contra Contagram
     * (15/08/2026). Lleva `saldo_inicial = -4650.00` con fecha 13/09/2021 para
     * que el Saldo Cta Cte Proveedores coincida con el panel de Contagram; la
     * explicación completa está en su propio campo `nota`.
     *
     * **Suma en el aging a propósito** (`CuentaCorriente::aging()` lo toma por
     * `entidadesConSaldoInicial`) pero se oculta de las vistas: no es un
     * proveedor con el que se opere. Ver {@see self::scopeVisibles()}.
     */
    public const AJUSTE_CONCILIACION = 'AJUSTE CONCILIACION CONTAGRAM';

    protected $table = 'proveedores';

    protected $fillable = [
        'nombre',
        'nombre_pila',
        'apellido',
        'pagina_web',
        'email',
        'telefono',
        'telefono_celular',
        'domicilio',
        'localidad',
        'provincia',
        'cp',
        'nota',
        'cuit',
        'razon_social',
        'tipo_documento',
        'condicion_iva_id',
        'tipo_comprobante_defecto',
        'domicilio_fiscal',
        'localidad_fiscal',
        'provincia_fiscal',
        'cp_fiscal',
        'telefono_fiscal',
        'telefono_celular_fiscal',
        'categoria_id',
        'nota_interna',
        'saldo_inicial',
        'saldo_inicial_fecha',
        'campos_personalizados',
        'activo',
    ];

    protected $casts = [
        'campos_personalizados' => 'array',
        'saldo_inicial' => 'decimal:2',
        'saldo_inicial_fecha' => 'date:Y-m-d',
        'activo' => 'boolean',
    ];

    /**
     * Excluye los proveedores internos que no deben verse en pantalla (hoy, el
     * de ajuste de conciliación). Va en los listados, selects e informes; NO en
     * el cálculo de saldos, donde tiene que seguir sumando.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Proveedor>  $query
     */
    public function scopeVisibles($query)
    {
        return $query->where('proveedores.nombre', '<>', self::AJUSTE_CONCILIACION);
    }

    public function condicionIva(): BelongsTo
    {
        return $this->belongsTo(CondicionIva::class, 'condicion_iva_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function contactos(): HasMany
    {
        return $this->hasMany(ProveedorContacto::class);
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'proveedor_id');
    }

    /**
     * ¿El proveedor tiene productos asociados? Bloquea la eliminación física
     * (FR-006), mismo patrón que Cliente::tieneOperaciones().
     */
    public function tieneOperaciones(): bool
    {
        return $this->productos()->exists();
    }
}
