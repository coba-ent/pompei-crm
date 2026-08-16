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

    /**
     * Contrapartida del anterior, con `saldo_inicial = +4650.00` al 16/08/2026.
     *
     * El desfasaje que tapa {@see self::AJUSTE_CONCILIACION} existe **sólo cuando se le pone
     * fecha de corte al panel de Contagram**: verificado el 16/08/2026 contra 2021, 2022, 2023,
     * 2024, 2025 y cada mes y día de 2026, todos piden el ajuste. Pero el panel **sin** fecha
     * —el saldo de hoy— y la propia lista de Movimientos de Contagram dan 4.650 más, y ahí
     * coinciden con el CRM sin ningún ajuste (12, 13 y 14/08 contrastados: 5 centavos).
     *
     * Como un `saldo_inicial` arranca en una fecha y sigue para siempre, el de 2021 no se puede
     * "terminar": hace falta esta segunda entidad que lo cancela desde el 16/08/2026 en adelante.
     * De ahí para acá los dos suman cero y el saldo de hoy queda limpio; hacia atrás sigue
     * actuando sólo el primero y los cortes históricos siguen coincidiendo.
     *
     * Si algún día aparece la causa real del desfasaje, **hay que borrar los dos**, no uno.
     */
    public const AJUSTE_CONCILIACION_CIERRE = 'AJUSTE CONCILIACION CONTAGRAM - CIERRE';

    /** Prefijo que comparten las dos entidades de ajuste, para ocultarlas de una. */
    public const PREFIJO_AJUSTE = 'AJUSTE CONCILIACION CONTAGRAM%';

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
        return $query->where('proveedores.nombre', 'not like', self::PREFIJO_AJUSTE);
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
