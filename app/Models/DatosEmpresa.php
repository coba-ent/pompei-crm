<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Mi Perfil: fila única (single-tenant) con los datos fiscales del negocio emisor. */
class DatosEmpresa extends Model
{
    protected $table = 'datos_empresa';

    protected $fillable = [
        'razon_social', 'cuit', 'domicilio_fiscal', 'condicion_iva', 'ingresos_brutos', 'ruta_logo',
        'mail_contador',
    ];

    public static function instancia(): ?self
    {
        return static::query()->first();
    }
}
