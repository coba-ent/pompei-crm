<?php

namespace App\Services\MercadoLibre;

use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibreCuenta;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Services\Stock\StockService;
use Illuminate\Support\Collection;

/**
 * Refleja en el CRM la existencia del centro de distribución de Mercado Libre (spec 065, US4).
 *
 * Es el **inverso** de `SincronizadorStock`: ahí el CRM manda y Mercado Libre obedece; acá
 * Mercado Libre manda y el CRM copia. Por eso vive aparte y no como una rama de aquél:
 *
 * - Sentido de datos opuesto (lee de ML, escribe en el CRM).
 * - Cortes previos distintos: el **modo sólo lectura no corta** (FR-014a), porque es un
 *   kill-switch de escrituras *hacia Mercado Libre* y esto no escribe nada allá (FR-009c).
 * - Recorre **todos** los vínculos Full, no sólo los pendientes (FR-009a): del lado de
 *   Mercado Libre nadie marca nada como pendiente, así que no hay señal que seguir.
 *
 * La existencia Full **no es escribible por API**: sólo cambia cuando Mercado Libre recibe
 * físicamente un envío o cuando vende.
 */
class SincronizadorStockFull
{
    public function __construct(
        private readonly ClienteMercadoLibre $cliente,
        private readonly StockService $stock,
    ) {
    }

    /**
     * @return array{ok: bool, tipo?: string, mensaje: string, actualizados: int, sin_cambios: int, con_error: int, conflictos: int}
     */
    public function ejecutar(): array
    {
        if ($corte = $this->verificarCortesLectura()) {
            return $corte;
        }

        // Sin publicaciones Full no hay nada que reflejar ni de qué avisar: el aviso de
        // depósito faltante sólo tiene sentido si existe algo que reflejar (FR-026). Salir
        // acá mantiene intactos los mensajes de una cuenta sin Full (SC-007).
        if (! MercadoLibrePublicacionProducto::soloFull()->exists()) {
            return $this->resultado(true, 'Sin publicaciones en Full.');
        }

        $depositoFull = MercadoLibreConfiguracion::actual()->depositoFullEfectivoONulo();

        // FR-014: sin depósito configurado la funcionalidad simplemente no opera. No se cae
        // a Deposito::porDefecto() a propósito: reflejaría la existencia del centro de
        // distribución de Mercado Libre sobre un depósito físico real del negocio.
        if (! $depositoFull) {
            return $this->resultado(false, 'Stock de Full no reflejado: no hay depósito para publicaciones Full configurado.', tipo: 'sin_deposito');
        }

        return $this->reflejar($depositoFull);
    }

    /**
     * Cortes de una operación de **lectura** (research R6): la función avanzada tiene que
     * estar activa y tiene que haber una cuenta conectada, pero —a diferencia del push—
     * el modo sólo lectura NO corta (FR-014a).
     */
    private function verificarCortesLectura(): ?array
    {
        if (! (bool) FuncionAvanzada::where('clave', 'mercadolibre')->value('activa')) {
            return $this->resultado(false, 'La función "Mercado Libre" está desactivada en Funciones Avanzadas.', tipo: 'bloqueada');
        }

        if (! MercadoLibreCuenta::conectada()->exists()) {
            return $this->resultado(false, 'No hay ninguna cuenta de Mercado Libre conectada. Volvé a conectar la cuenta.', tipo: 'bloqueada');
        }

        return null;
    }

    private function reflejar(Deposito $depositoFull): array
    {
        $actualizados = 0;
        $sinCambios = 0;
        $conError = 0;
        $inventariosEnConflicto = [];

        foreach ($this->vinculosPorInventario() as $inventoryId => $vinculos) {
            $productos = $vinculos->pluck('producto')->filter()->unique('id');

            // FR-014b: el producto se borró del CRM. No hay dónde imputar; se saltea sin
            // contarlo como error, igual que hace el push.
            if ($productos->isEmpty()) {
                continue;
            }

            // FR-014c: un mismo inventario compartido por productos DISTINTOS no se puede
            // repartir — no hay dato que diga cuántas unidades son de cada uno. Imputarlo
            // a cualquiera sería inventar existencias, así que se reporta y no se toca.
            if ($productos->count() > 1) {
                $inventariosEnConflicto[] = $inventoryId;

                continue;
            }

            $disponibleEnMl = $this->consultarDisponible((string) $inventoryId);

            // Ante fallo se conserva la existencia actual: poner cero sería peor que no
            // hacer nada, porque frenaría ventas reales por un problema de red.
            if ($disponibleEnMl === null) {
                $conError++;

                continue;
            }

            $producto = $productos->first();
            $delta = $disponibleEnMl - $this->stock->disponibilidad($producto, null, $depositoFull);

            // FR-012: idempotencia. Sin diferencia no se genera movimiento, así que el
            // historial de stock no se llena de ajustes de cero en cada corrida.
            if ((int) round($delta) === 0) {
                $sinCambios++;

                continue;
            }

            // FR-010: origen trazable, para poder responder después por qué se movió el stock.
            $this->stock->ajustar(
                $producto, null, $depositoFull, $delta,
                "Reflejo de stock Full de Mercado Libre (inventario {$inventoryId})"
            );

            $actualizados++;
        }

        $conflictos = count($inventariosEnConflicto);
        $mensaje = "{$actualizados} productos actualizados desde Full.";

        if ($conflictos > 0) {
            $mensaje .= ' '.$conflictos.' inventario(s) compartidos por productos distintos, sin reflejar: '
                .implode(', ', $inventariosEnConflicto).'.';
        }

        if ($conError > 0) {
            $mensaje .= " {$conError} inventario(s) con error, se conservó la existencia actual.";
        }

        return $this->resultado(true, $mensaje, $actualizados, $sinCambios, $conError, $conflictos);
    }

    /**
     * Vínculos Full agrupados por inventario (FR-009b): la existencia vive en el inventario,
     * no en la publicación, así que dos publicaciones que comparten `inventory_id` comparten
     * las mismas unidades y computarlas dos veces duplicaría el stock.
     *
     * Los vínculos sin `inventory_id` quedan afuera: sin ese identificador no hay a qué
     * endpoint preguntarle. Se reclasifican solos en la próxima corrida del multiget.
     *
     * @return Collection<string, Collection<int, MercadoLibrePublicacionProducto>>
     */
    private function vinculosPorInventario(): Collection
    {
        return MercadoLibrePublicacionProducto::soloFull()
            ->whereNotNull('inventory_id')
            ->with('producto')
            ->get()
            ->groupBy('inventory_id');
    }

    /** `available_quantity` es la existencia **vendible**; `not_available_quantity` no se computa (FR-009). */
    private function consultarDisponible(string $inventoryId): ?float
    {
        $respuesta = $this->cliente->obtener(
            'reflejar_stock_full',
            "/inventories/{$inventoryId}/stock/fulfillment"
        );

        if ($respuesta->fallo()) {
            return null;
        }

        $disponible = $respuesta->datos['available_quantity'] ?? null;

        return $disponible === null ? null : (float) $disponible;
    }

    private function resultado(
        bool $ok,
        string $mensaje,
        int $actualizados = 0,
        int $sinCambios = 0,
        int $conError = 0,
        int $conflictos = 0,
        ?string $tipo = null,
    ): array {
        return array_filter([
            'ok' => $ok,
            'tipo' => $tipo,
            'mensaje' => $mensaje,
            'actualizados' => $actualizados,
            'sin_cambios' => $sinCambios,
            'con_error' => $conError,
            'conflictos' => $conflictos,
        ], static fn ($valor) => $valor !== null);
    }
}
