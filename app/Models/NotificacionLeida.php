<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * spec 073 — marca de "ya lo vi" de un usuario sobre un episodio de alerta.
 *
 * La fila muere cuando el problema se resuelve (limpieza oportunista en el resumen), que es
 * lo que hace que una alerta resuelta y vuelta a aparecer cuente de nuevo como no leída.
 */
class NotificacionLeida extends Model
{
    protected $table = 'notificaciones_leidas';

    public $timestamps = false;

    protected $fillable = ['user_id', 'clave', 'leida_en'];

    protected $casts = ['leida_en' => 'datetime'];
}
