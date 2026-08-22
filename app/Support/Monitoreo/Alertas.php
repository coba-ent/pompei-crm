<?php

namespace App\Support\Monitoreo;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * spec 073 — el conjunto de alertas vigentes del sistema y sus claves de episodio.
 *
 * Lo comparten el panel de Monitoreo y la barra superior: duplicar el cálculo garantizaría
 * que las claves se desincronicen y que el "leído" deje de funcionar.
 *
 * Sigue el aislamiento de lógica del panel original: consultas directas con `DB::table`,
 * sin depender de servicios ni observers del resto de la app.
 */
class Alertas
{
    /** Depósito Local: es el que responde "¿le compro al proveedor?". */
    private int $depositoLocal;

    /** Depósito Full de Mercado Libre (puede no estar configurado). */
    private ?int $depositoFull;

    public function __construct()
    {
        $configuracion = DB::table('ml_configuracion')->first();

        $this->depositoLocal = (int) ($configuracion->deposito_id ?? 0);
        $this->depositoFull = ! empty($configuracion->deposito_full_id)
            ? (int) $configuracion->deposito_full_id
            : null;
    }

    public function depositoLocal(): int
    {
        return $this->depositoLocal;
    }

    public function depositoFull(): ?int
    {
        return $this->depositoFull;
    }

    /** ¿La integración de Mercado Libre está utilizable para los bloques que dependen de ella? */
    public function mlConfigurado(): bool
    {
        return $this->depositoLocal > 0;
    }

    // -----------------------------------------------------------------
    // Consultas base — las comparten el panel (DataTables) y el resumen.
    // -----------------------------------------------------------------

    /**
     * Productos a reponer: stock en el depósito **Local** menor o igual al punto de reposición
     * (FR-018). Todo el catálogo, esté publicado en Mercado Libre o no.
     */
    public function queryReponer(): Builder
    {
        return DB::table('productos as p')
            ->leftJoin('stocks as sl', function ($j) {
                $j->on('sl.producto_id', '=', 'p.id')->where('sl.deposito_id', $this->depositoLocal);
            })
            ->leftJoin('stocks as sf', function ($j) {
                $j->on('sf.producto_id', '=', 'p.id')->where('sf.deposito_id', $this->depositoFull ?? 0);
            })
            ->leftJoin('proveedores as pv', 'pv.id', '=', 'p.proveedor_id')
            ->where('p.tipo', 'producto')
            ->where('p.activo', true)
            ->whereNotNull('p.punto_reposicion')
            ->where('p.punto_reposicion', '>', 0)
            ->whereRaw('COALESCE(sl.cantidad, 0) <= p.punto_reposicion')
            ->select(
                'p.id',
                'p.nombre',
                'p.codigo',
                'p.punto_reposicion',
                DB::raw('COALESCE(sl.cantidad, 0) as stock_local'),
                DB::raw('COALESCE(sf.cantidad, 0) as stock_full'),
                'pv.nombre as proveedor',
            );
    }

    /**
     * Productos publicados en Mercado Libre cuyo stock **vendible** (Local + Full) está en o por
     * debajo del punto de reposición (FR-019).
     *
     * OJO: `ml_configuracion.deposito_id` **es** el Local, así que "el depósito de Mercado Libre"
     * habría dado la misma lista que `queryReponer()`. Lo que distingue a este bloque es Full:
     * un producto con 1 en Local y 50 en Full hay que reponerlo, pero su publicación no corre
     * ningún riesgo.
     */
    public function queryRiesgoMl(): Builder
    {
        return DB::table('productos as p')
            ->join('ml_publicacion_producto as m', 'm.producto_id', '=', 'p.id')
            ->leftJoin('stocks as sl', function ($j) {
                $j->on('sl.producto_id', '=', 'p.id')->where('sl.deposito_id', $this->depositoLocal);
            })
            ->leftJoin('stocks as sf', function ($j) {
                $j->on('sf.producto_id', '=', 'p.id')->where('sf.deposito_id', $this->depositoFull ?? 0);
            })
            ->where('p.tipo', 'producto')
            ->where('p.activo', true)
            ->whereNotNull('p.punto_reposicion')
            ->where('p.punto_reposicion', '>', 0)
            ->whereRaw('COALESCE(sl.cantidad, 0) + COALESCE(sf.cantidad, 0) <= p.punto_reposicion')
            ->select(
                'p.id',
                'p.nombre',
                'p.punto_reposicion',
                'm.ml_item_id',
                DB::raw('COALESCE(sl.cantidad, 0) as stock_local'),
                DB::raw('COALESCE(sf.cantidad, 0) as stock_full'),
            );
    }

    /** Publicaciones de Mercado Libre que no logran actualizar su stock (FR-016). */
    public function queryPublicacionesFallando(): Builder
    {
        return DB::table('ml_publicacion_producto as p')
            ->leftJoin('productos as pr', 'pr.id', '=', 'p.producto_id')
            ->leftJoin('stocks as s', function ($j) {
                $j->on('s.producto_id', '=', 'p.producto_id')->where('s.deposito_id', $this->depositoLocal);
            })
            ->whereNotNull('p.stock_error')
            ->select(
                'p.ml_item_id',
                'p.titulo_ml',
                'p.producto_id',
                'p.stock_error',
                'p.stock_error_desde',
                'p.stock_intentos_fallidos',
                'p.stock_requiere_intervencion',
                'p.ultimo_stock_publicado',
                'pr.nombre as producto',
                DB::raw('COALESCE(s.cantidad, 0) as stock'),
            );
    }

    /** Una publicación frenada por la moderación de ML no tiene acción posible desde el CRM. */
    public static function esModeracion(?string $error): bool
    {
        $texto = (string) $error;

        return str_contains($texto, 'under_review') || str_contains($texto, 'forbidden');
    }

    // -----------------------------------------------------------------
    // Notificaciones
    // -----------------------------------------------------------------

    /**
     * Las notificaciones vigentes para un usuario, cruzadas con sus marcas de lectura.
     *
     * @return array{items: array<int, array<string, mixed>>, sinLeer: int, vigentes: array<int, string>, porTipo: array<string, int>}
     */
    public function notificaciones(int $userId, int $muestra = 20): array
    {
        $alertas = [];

        foreach ($this->queryReponer()->orderBy('p.nombre')->get() as $f) {
            $alertas[] = [
                'clave' => 'reposicion:'.$f->id,
                'tipo' => 'reposicion',
                'titulo' => $f->nombre,
                'detalle' => 'Quedan '.$this->numero($f->stock_local).', el punto de reposición es '.(int) $f->punto_reposicion,
                'cuando' => null,
                'url' => url('/monitoreo?bloque=reponer'),
                'orden' => 1,
            ];
        }

        if ($this->mlConfigurado()) {
            foreach ($this->queryPublicacionesFallando()->orderByDesc('p.stock_error_desde')->get() as $f) {
                $alertas[] = [
                    'clave' => 'ml_stock:'.$f->ml_item_id,
                    'tipo' => 'ml_stock',
                    'titulo' => $f->titulo_ml ?: ($f->producto ?: $f->ml_item_id),
                    'detalle' => (string) $f->stock_error,
                    'cuando' => $this->fechaCorta($f->stock_error_desde),
                    'url' => url('/monitoreo?bloque=publicaciones'),
                    'orden' => 0,
                ];
            }
        }

        $vigentes = array_column($alertas, 'clave');

        // Limpieza oportunista: la marca de lectura de un problema ya resuelto se borra, así que
        // si el problema vuelve a aparecer la notificación nace no leída (FR-035).
        $this->limpiarMarcasHuerfanas($userId, $vigentes);

        $leidas = DB::table('notificaciones_leidas')
            ->where('user_id', $userId)
            ->pluck('clave')
            ->flip();

        $sinLeer = 0;
        $porTipo = [];
        foreach ($alertas as $i => $alerta) {
            $leida = $leidas->has($alerta['clave']);
            $alertas[$i]['leida'] = $leida;
            if (! $leida) {
                $sinLeer++;
                $porTipo[$alerta['tipo']] = ($porTipo[$alerta['tipo']] ?? 0) + 1;
            }
        }

        // Primero lo no leído; dentro de eso, primero las publicaciones fallando (orden 0).
        usort($alertas, function ($a, $b) {
            return [$a['leida'], $a['orden'], $a['titulo']] <=> [$b['leida'], $b['orden'], $b['titulo']];
        });

        $items = array_map(function ($a) {
            unset($a['orden']);

            return $a;
        }, array_slice($alertas, 0, $muestra));

        return ['items' => $items, 'sinLeer' => $sinLeer, 'vigentes' => $vigentes, 'porTipo' => $porTipo];
    }

    /**
     * Borra las marcas de lectura del usuario cuya clave ya no está entre las alertas vigentes.
     *
     * Sin cron y sin política de retención: se hace en cada cálculo del resumen.
     *
     * @param  array<int, string>  $vigentes
     */
    public function limpiarMarcasHuerfanas(int $userId, array $vigentes): void
    {
        $query = DB::table('notificaciones_leidas')->where('user_id', $userId);

        if ($vigentes !== []) {
            $query->whereNotIn('clave', $vigentes);
        }

        $query->delete();
    }

    // -----------------------------------------------------------------

    private function numero(mixed $valor): string
    {
        $n = (float) $valor;

        return $n == (int) $n ? (string) (int) $n : rtrim(rtrim(number_format($n, 3, ',', ''), '0'), ',');
    }

    private function fechaCorta(?string $fecha): ?string
    {
        return $fecha
            ? now()->parse($fecha)->timezone(config('app.display_timezone'))->format('d/m H:i')
            : null;
    }
}
