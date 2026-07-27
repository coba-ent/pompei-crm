<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    use HasFactory;

    protected $table = 'clientes';

    protected $fillable = [
        'nombre',
        'nombre_pila',
        'apellido',
        'apodo_ml',
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
        'lista_precio_id',
        'descuento_general_pct',
        'nota_cliente',
        'saldo_inicial',
        'saldo_inicial_fecha',
        'campos_personalizados',
        'activo',
    ];

    protected $casts = [
        'campos_personalizados' => 'array',
        'saldo_inicial' => 'decimal:2',
        'saldo_inicial_fecha' => 'date:Y-m-d',
        'descuento_general_pct' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function condicionIva(): BelongsTo
    {
        return $this->belongsTo(CondicionIva::class, 'condicion_iva_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function listaPrecio(): BelongsTo
    {
        return $this->belongsTo(ListaPrecio::class, 'lista_precio_id');
    }

    public function contactos(): HasMany
    {
        return $this->hasMany(ClienteContacto::class);
    }

    /**
     * Si la condición de IVA del cliente exige CUIT para poder facturar
     * (Responsable Inscripto, Monotributista). Principio III.
     */
    public function requiereCuitParaFacturar(): bool
    {
        return (bool) optional($this->condicionIva)->requiere_cuit;
    }

    /**
     * Un cliente es apto para facturar si tiene condición de IVA cargada.
     * Si esa condición exige CUIT, además debe tener un CUIT presente.
     * Regla derivada (no persistida) — refleja siempre los datos actuales.
     */
    public function esAptoParaFacturar(): bool
    {
        if (empty($this->condicion_iva_id)) {
            return false;
        }

        if ($this->requiereCuitParaFacturar() && empty($this->cuit)) {
            return false;
        }

        return true;
    }

    /**
     * ¿El cliente tiene operaciones asociadas (presupuestos, ventas, cobros,
     * comprobantes)? Costura extensible: hoy no existen esos módulos, por lo que
     * devuelve false. Cada módulo futuro sumará su verificación aquí.
     * Bloquea la eliminación física cuando sea true. FR-022/FR-023.
     */
    public function tieneOperaciones(): bool
    {
        return false;
    }
}
