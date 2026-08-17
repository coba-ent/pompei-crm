<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una vista guardada de "Arma tu Informe" (spec 069).
 *
 * Persiste la configuración de un cruce —qué dimensiones en filas y columnas, qué medida, qué
 * agregador, qué valores se excluyeron—, no sus resultados: al abrir la pestaña el cruce se
 * recalcula contra los datos vigentes.
 *
 * Las vistas son **compartidas**: `creado_por_id` queda para auditoría, pero cualquiera con el
 * permiso `informes.ver` las ve y las puede borrar (FR-034). No hay borrado lógico — ver el
 * comentario de la migración.
 *
 * @property string $informe  'ventas' | 'compras'
 * @property array{filas: list<string>, columnas: list<string>, dato: string, accion: string, exclusiones: array<string, list<string>>} $config
 */
class InformeVista extends Model
{
    protected $table = 'informes_vistas';

    protected $fillable = ['informe', 'descripcion', 'config', 'creado_por_id'];

    protected $casts = [
        'config' => 'array',
    ];

    /** Los dos informes sobre los que Contagram monta el motor de tablas dinámicas. */
    public const INFORMES = ['ventas', 'compras'];

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por_id');
    }

    /**
     * Vistas de un informe, en el orden en que se muestran las pestañas.
     *
     * Se ordena por `id` y no por `descripcion`: las pestañas aparecen en el orden en que se
     * fueron creando, que es lo que espera quien las armó.
     */
    public function scopePorInforme(Builder $consulta, string $informe): Builder
    {
        return $consulta->where('informe', $informe)->orderBy('id');
    }
}
