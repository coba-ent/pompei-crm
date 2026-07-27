<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    use HasFactory;

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
