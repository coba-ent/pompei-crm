<?php

namespace App\Services\Productos;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Integraciones\TiendanubeConexionRest;
use App\Models\Integraciones\TiendanubeVarianteProducto;
use App\Models\ListaPrecio;
use App\Models\Producto;
use App\Observers\PrecioProductoObserver;
use App\Services\AuditoriaService;
use App\Services\MercadoLibre\SincronizadorPrecios as SincronizadorPreciosMercadoLibre;
use App\Services\Tiendanube\SincronizadorPrecios as SincronizadorPreciosTiendanube;
use App\Support\OrigenCambioPrecio;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Modificar Precio de Venta" / "Modificar Costo" de Acciones Masivas, para miles de productos.
 *
 * El camino original (un `updateOrCreate()` por precio, con `PrecioProductoObserver` disparando
 * por cada uno) hacía ~73 consultas por producto: el 19/10/2026 la clienta intentó un aumento
 * sobre el catálogo y las dos veces PHP cortó a los 30 s sin aplicar nada. Acá la tanda se
 * resuelve con un puñado de consultas en bloque, y la pantalla la parte en tandas
 * (`resources/js/productos.js`), igual que la importación.
 *
 * Como las escrituras van en bloque y no pasan por Eloquent, el observer NO corre: este servicio
 * reproduce sus tres ramas —auditoría, Mercado Libre, Tiendanube— con las mismas reglas. La
 * diferencia deliberada es que los envíos a las integraciones no salen dentro del pedido que
 * guarda los precios: los vínculos afectados quedan `precio_pendiente` y la pantalla los manda
 * después, en tandas acotadas por tiempo (`enviar()`). Mandarlos en el mismo pedido —un PUT de
 * hasta 30 s por publicación— volvería a colgar la pantalla.
 */
class AjusteMasivoPrecios
{
    /** Cuánto se recuerda qué productos ya aplicó una corrida (para que reintentar no duplique). */
    private const TTL_CORRIDA_SEGUNDOS = 86400;

    public function __construct(
        private readonly AuditoriaService $auditoria,
        private readonly SincronizadorPreciosMercadoLibre $sincronizadorMercadoLibre,
        private readonly SincronizadorPreciosTiendanube $sincronizadorTiendanube,
    ) {}

    /**
     * Aplica el ajuste a una tanda de productos, atómica.
     *
     * `$corrida` identifica la edición masiva completa (la genera la pantalla). Un aumento NO es
     * idempotente: si la conexión se corta después de que la tanda se guardó, reintentarla
     * aplicaría el 10% dos veces. Por eso se recuerda qué productos ya aplicó cada corrida y un
     * reintento los saltea.
     *
     * @param  array<int, int>  $productoIds
     * @param  array<string, array{valor: numeric-string|float, signo: string}>  $campos
     * @return array{actualizados: int, ya_aplicados: int, envios: array{ml: array<int, int>, tn: array<int, int>}}
     */
    public function aplicar(array $productoIds, array $campos, string $modo, bool $redondear, ?string $corrida = null): array
    {
        $claveCorrida = $corrida ? 'productos:ajuste-masivo:'.$corrida : null;
        $yaAplicados = $claveCorrida ? Cache::get($claveCorrida, []) : [];
        $pendientes = array_values(array_diff(array_map('intval', $productoIds), $yaAplicados));

        $envios = ['ml' => [], 'tn' => []];

        if ($pendientes !== []) {
            $envios = DB::transaction(function () use ($pendientes, $campos, $modo, $redondear, $claveCorrida, $yaAplicados) {
                $envios = $this->aplicarEnBloque($pendientes, $campos, $modo, $redondear);

                // Dentro de la transacción a propósito: si el COMMIT llegara a fallar después de
                // anotarlo, el reintento saltearía productos (se aplica de menos) en vez de
                // aplicarles el ajuste dos veces. Entre los dos errores posibles, éste es el visible
                // y el reversible.
                if ($claveCorrida) {
                    Cache::put($claveCorrida, array_merge($yaAplicados, $pendientes), self::TTL_CORRIDA_SEGUNDOS);
                }

                return $envios;
            });
        }

        return [
            'actualizados' => count($pendientes),
            'ya_aplicados' => count($productoIds) - count($pendientes),
            'envios' => $envios,
        ];
    }

    /**
     * @param  array<int, int>  $productoIds
     * @param  array<string, array{valor: numeric-string|float, signo: string}>  $campos
     * @return array{ml: array<int, int>, tn: array<int, int>}
     */
    private function aplicarEnBloque(array $productoIds, array $campos, string $modo, bool $redondear): array
    {
        $ahora = now();
        $productos = Producto::whereIn('id', $productoIds)->get(['id', 'nombre', 'precio_venta', 'costo'])->keyBy('id');

        // ---- Precio de Venta / Costo (columnas de `productos`, sin auditoría ni integraciones) ----
        foreach (['precio_venta', 'costo'] as $columna) {
            if (! isset($campos[$columna])) {
                continue;
            }

            $nuevos = [];
            foreach ($productos as $producto) {
                $actual = (float) $producto->{$columna};
                $nuevo = $this->ajustarValor($actual, $modo, $campos[$columna], $redondear);
                if (round($actual, 2) !== $nuevo) {
                    $nuevos[$producto->id] = $nuevo;
                }
            }
            $this->actualizarColumna('productos', $columna, $nuevos, $ahora);
        }

        // ---- Listas de precio (`precios_producto`) ----
        $listaIds = [];
        foreach (array_keys($campos) as $campo) {
            if (str_starts_with($campo, 'lista_')) {
                $listaIds[] = (int) substr($campo, 6);
            }
        }

        if ($listaIds === []) {
            return ['ml' => [], 'tn' => []];
        }

        $nombresLista = ListaPrecio::whereIn('id', $listaIds)->pluck('nombre', 'id');
        $existentes = DB::table('precios_producto')
            ->whereIn('producto_id', $productoIds)
            ->whereIn('lista_precio_id', $listaIds)
            ->get(['producto_id', 'lista_precio_id', 'precio'])
            ->keyBy(fn ($fila) => $fila->producto_id.'-'.$fila->lista_precio_id);

        $filas = [];
        $cambiadas = []; // producto_id => [lista_precio_id, ...]

        // La auditoría va en buffer (INSERT múltiple), igual que en la importación.
        $this->auditoria->iniciarBuffer();

        try {
            OrigenCambioPrecio::durante(OrigenCambioPrecio::EDICION_MASIVA, function () use ($productos, $listaIds, $campos, $modo, $redondear, $existentes, $nombresLista, $ahora, &$filas, &$cambiadas) {
                foreach ($productos as $producto) {
                    foreach ($listaIds as $listaId) {
                        $fila = $existentes->get($producto->id.'-'.$listaId);
                        $anterior = $fila ? (float) $fila->precio : null;
                        $nuevo = $this->ajustarValor($anterior ?? 0.0, $modo, $campos['lista_'.$listaId], $redondear);

                        // Un producto sin precio en la lista + un porcentaje da 0: no se crea un
                        // precio $0 (antes sí, y en una lista de Mercado Libre se publicaba).
                        if ($anterior === null && $nuevo === 0.0) {
                            continue;
                        }

                        if ($anterior !== null && round($anterior, 2) === $nuevo) {
                            continue;
                        }

                        $filas[] = [
                            'producto_id' => $producto->id,
                            'lista_precio_id' => $listaId,
                            'precio' => $nuevo,
                            'created_at' => $ahora,
                            'updated_at' => $ahora,
                        ];
                        $cambiadas[$producto->id][] = $listaId;

                        $this->auditoria->registrarEvento(
                            $anterior === null ? 'creo' : 'modifico',
                            'precio_producto',
                            $producto,
                            PrecioProductoObserver::detalleCambio(
                                $producto->nombre,
                                $nombresLista[$listaId] ?? "Lista #{$listaId}",
                                $anterior,
                                $nuevo,
                            ),
                            $nuevo,
                        );
                    }
                }
            });

            foreach (array_chunk($filas, 500) as $lote) {
                // `updated_at` sí, `created_at` no: en una fila que ya existía se conserva la fecha de alta.
                DB::table('precios_producto')->upsert($lote, ['producto_id', 'lista_precio_id'], ['precio', 'updated_at']);
            }
        } finally {
            $this->auditoria->vaciarBuffer();
        }

        return [
            'ml' => $this->vinculosMercadoLibreAfectados($cambiadas),
            'tn' => $this->vinculosTiendanubeAfectados($cambiadas),
        ];
    }

    /**
     * Mismas reglas que `PrecioProductoObserver::ramaMercadoLibre()`: cada publicación cotiza por
     * la lista que le corresponde por tipo, y sin tipo conocido queda pendiente sin enviarse.
     *
     * @param  array<int, array<int, int>>  $cambiadas
     * @return array<int, int> ids de vínculo a enviar
     */
    private function vinculosMercadoLibreAfectados(array $cambiadas): array
    {
        $configuracion = MercadoLibreConfiguracion::actual();
        $listasMl = array_map('intval', array_filter([$configuracion->lista_precio_id, $configuracion->lista_precio_id_premium]));

        $productoIds = array_keys(array_filter($cambiadas, fn ($listas) => array_intersect($listas, $listasMl) !== []));
        if ($productoIds === []) {
            return [];
        }

        $aEnviar = [];
        $sinTipo = [];

        foreach (MercadoLibrePublicacionProducto::with('producto')->whereIn('producto_id', $productoIds)->get() as $vinculo) {
            if ($vinculo->listing_type_id === null) {
                $sinTipo[] = $vinculo->id;

                continue;
            }

            $lista = $this->sincronizadorMercadoLibre->resolverListaPrecio($vinculo, $configuracion);
            if ($lista !== null && in_array($lista, $cambiadas[$vinculo->producto_id], true)) {
                $aEnviar[] = $vinculo->id;
            }
        }

        // Pendientes desde ya: si el envío posterior no llega a correr (se cierra la pestaña, se
        // corta la conexión), el cambio queda marcado para el próximo intento en vez de perderse.
        $marcar = array_merge($aEnviar, $sinTipo);
        if ($marcar !== []) {
            MercadoLibrePublicacionProducto::whereIn('id', $marcar)->update(['precio_pendiente' => true]);
        }

        return $aEnviar;
    }

    /**
     * Mismas reglas que `PrecioProductoObserver::ramaTiendanube()`: cualquier cambio en la lista
     * normal o en la promocional reenvía la variante completa.
     *
     * @param  array<int, array<int, int>>  $cambiadas
     * @return array<int, int> ids de vínculo a enviar
     */
    private function vinculosTiendanubeAfectados(array $cambiadas): array
    {
        $conexion = TiendanubeConexionRest::actual();
        $listasTn = array_map('intval', array_filter([$conexion->lista_precio_id, $conexion->lista_precio_promocional_id]));

        $productoIds = array_keys(array_filter($cambiadas, fn ($listas) => array_intersect($listas, $listasTn) !== []));
        if ($productoIds === []) {
            return [];
        }

        $aEnviar = TiendanubeVarianteProducto::whereIn('producto_id', $productoIds)->pluck('id')->all();
        if ($aEnviar !== []) {
            TiendanubeVarianteProducto::whereIn('id', $aEnviar)->update(['precio_pendiente' => true]);
        }

        return $aEnviar;
    }

    /**
     * Envía a Mercado Libre y Tiendanube los vínculos que dejó pendientes `aplicar()`, hasta agotar
     * el presupuesto de tiempo: cada PUT puede tardar hasta 30 s, así que la cantidad por pedido no
     * alcanza para acotar la duración. Devuelve cuántos de cada lista procesó (en orden), para que
     * la pantalla siga con el resto. Reintentar es seguro: siempre se manda el precio vigente.
     *
     * Pasa por `enviarUno()` de cada integración, así que rigen los mismos cortes que en cualquier
     * otro envío (función desactivada, sólo lectura, corte de seguridad de precios de ML).
     *
     * @param  array<int, int>  $mlIds
     * @param  array<int, int>  $tnIds
     * @return array{ml_procesados: int, tn_procesados: int, enviados: int, no_enviados: int, ml_bloqueo: ?string, tn_bloqueo: ?string}
     */
    public function enviar(array $mlIds, array $tnIds, float $presupuestoSegundos = 15.0): array
    {
        $inicio = microtime(true);
        $resultado = ['ml_procesados' => 0, 'tn_procesados' => 0, 'enviados' => 0, 'no_enviados' => 0, 'ml_bloqueo' => null, 'tn_bloqueo' => null];
        $agotado = fn () => (microtime(true) - $inicio) >= $presupuestoSegundos;

        if ($mlIds !== []) {
            // Con la integración bloqueada no se intenta vínculo por vínculo (cada intento dejaría
            // un registro de bloqueo): quedan todos pendientes, que es lo que ya son.
            $resultado['ml_bloqueo'] = $this->sincronizadorMercadoLibre->motivoBloqueoEscritura();

            if ($resultado['ml_bloqueo'] !== null) {
                $resultado['ml_procesados'] = count($mlIds);
                $resultado['no_enviados'] += count($mlIds);
            } else {
                $configuracion = MercadoLibreConfiguracion::actual();
                $vinculos = MercadoLibrePublicacionProducto::with('producto')->whereIn('id', $mlIds)->get()->keyBy('id');

                foreach ($mlIds as $id) {
                    if ($agotado()) {
                        break;
                    }
                    $resultado['ml_procesados']++;

                    $vinculo = $vinculos->get($id);
                    $lista = $vinculo?->producto ? $this->sincronizadorMercadoLibre->resolverListaPrecio($vinculo, $configuracion) : null;
                    $precio = $lista ? $vinculo->producto->precios()->where('lista_precio_id', $lista)->value('precio') : null;

                    if ($precio !== null && $this->sincronizadorMercadoLibre->enviarUno($vinculo, (float) $precio)) {
                        $resultado['enviados']++;
                    } else {
                        $resultado['no_enviados']++;
                    }
                }
            }
        }

        if ($tnIds !== [] && $resultado['ml_procesados'] === count($mlIds)) {
            $resultado['tn_bloqueo'] = $this->sincronizadorTiendanube->motivoBloqueoEscritura();

            if ($resultado['tn_bloqueo'] !== null) {
                $resultado['tn_procesados'] = count($tnIds);
                $resultado['no_enviados'] += count($tnIds);
            } else {
                $vinculos = TiendanubeVarianteProducto::with('producto')->whereIn('id', $tnIds)->get()->keyBy('id');

                foreach ($tnIds as $id) {
                    if ($agotado()) {
                        break;
                    }
                    $resultado['tn_procesados']++;

                    $vinculo = $vinculos->get($id);
                    if ($vinculo && $this->sincronizadorTiendanube->enviarUno($vinculo)) {
                        $resultado['enviados']++;
                    } else {
                        $resultado['no_enviados']++;
                    }
                }
            }
        }

        return $resultado;
    }

    /**
     * @param  array{valor: numeric-string|float, signo: string}  $ajuste
     */
    public function ajustarValor(float $actual, string $modo, array $ajuste, bool $redondear): float
    {
        $valor = (float) $ajuste['valor'];
        $delta = $modo === 'porcentaje' ? $actual * ($valor / 100) : $valor;
        $nuevo = $ajuste['signo'] === 'disminuir' ? $actual - $delta : $actual + $delta;
        $nuevo = max(0.0, $nuevo);

        return (float) ($redondear ? round($nuevo) : round($nuevo, 2));
    }

    /**
     * UPDATE ... SET col = CASE id WHEN ... END en bloque (no un UPDATE por producto).
     *
     * @param  array<int, float>  $valores  id => valor nuevo
     */
    private function actualizarColumna(string $tabla, string $columna, array $valores, DateTimeInterface $ahora): void
    {
        foreach (array_chunk($valores, 500, true) as $lote) {
            // Valores interpolados y no enlazados: `update()` no acepta bindings dentro de una
            // expresión cruda. Es seguro porque los dos son numéricos y se castean acá.
            $casos = [];
            foreach ($lote as $id => $valor) {
                $casos[] = sprintf('WHEN %d THEN %.2F', $id, $valor);
            }

            DB::table($tabla)
                ->whereIn('id', array_keys($lote))
                ->update([
                    $columna => DB::raw('CASE id '.implode(' ', $casos).' END'),
                    'updated_at' => $ahora,
                ]);
        }
    }
}
