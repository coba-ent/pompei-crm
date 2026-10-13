<?php

namespace App\Services\Stock;

use App\Models\Compra;
use App\Models\Venta;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Movimientos de stock que sólo existen porque se EDITÓ una Venta o una Compra, para ocultarlos
 * del Informe de Stock (pedido de la clienta, 13/10/2026).
 *
 * Editar un documento no mueve stock "por diferencia": StockDeVenta/StockDeCompra revierten lo que
 * había (un movimiento opuesto por renglón) y vuelven a aplicar lo nuevo. Cada edición deja así un
 * par que se anula —el renglón original y su reverso—, y el reverso queda con la hora de la edición
 * mientras la reaplicación lleva la fecha del documento. En el informe la compra aparecía como
 * "Disminución" y como último movimiento del día, aunque el stock nunca bajó.
 *
 * Se ocultan sólo esos pares: por documento + producto + depósito, un movimiento y otro POSTERIOR
 * (creado en otro momento) de la misma cantidad con signo contrario. Lo que queda visible es lo que
 * el documento aplica hoy. Excepción: si el documento está eliminado, su último par es la
 * eliminación y se deja a la vista, porque eso sí es algo que pasó.
 *
 * No se borra ni se toca ningún movimiento: el saldo corrido del informe se sigue calculando sobre
 * todos, y como cada par oculto suma cero, las filas visibles cierran igual.
 */
class MovimientosDeEdicion
{
    /** @return list<int> */
    public function idsOcultos(): array
    {
        // La clave cambia con cada movimiento nuevo, así que el cálculo (≈1,5 s sobre ~37 mil
        // movimientos) se hace una vez por cambio de stock y no en cada página del informe.
        $ultimo = (int) DB::table('movimientos_stock')->max('id');

        return Cache::remember("informe-stock:ocultos-edicion:{$ultimo}", now()->addDay(), fn () => $this->calcular());
    }

    /** @return list<int> */
    private function calcular(): array
    {
        $tipos = [Venta::class, Compra::class];

        $particiones = DB::table('movimientos_stock')
            ->whereIn('origen_type', $tipos)
            ->groupBy('origen_type', 'origen_id', 'producto_id', 'variante_id', 'deposito_id')
            ->havingRaw('COUNT(*) > 1')
            ->select('origen_type', 'origen_id')
            ->distinct()
            ->get();

        if ($particiones->isEmpty()) {
            return [];
        }

        $eliminados = [
            Venta::class => Venta::onlyTrashed()->whereIn('id', $particiones->where('origen_type', Venta::class)->pluck('origen_id'))->pluck('id')->flip(),
            Compra::class => Compra::onlyTrashed()->whereIn('id', $particiones->where('origen_type', Compra::class)->pluck('origen_id'))->pluck('id')->flip(),
        ];

        $filas = DB::table('movimientos_stock')
            ->whereIn('origen_type', $tipos)
            ->whereIn('origen_id', $particiones->pluck('origen_id')->unique())
            ->orderBy('id')
            ->get(['id', 'origen_type', 'origen_id', 'producto_id', 'variante_id', 'deposito_id', 'cantidad', 'created_at']);

        $ocultos = [];

        foreach ($filas->groupBy(fn ($f) => "{$f->origen_type}|{$f->origen_id}|{$f->producto_id}|{$f->variante_id}|{$f->deposito_id}") as $grupo) {
            if ($grupo->count() < 2) {
                continue;
            }

            $pares = $this->pares($grupo->values()->all());

            $primera = $grupo->first();
            if ($pares && isset($eliminados[$primera->origen_type][$primera->origen_id])) {
                array_pop($pares);
            }

            foreach ($pares as [$a, $b]) {
                $ocultos[] = $a;
                $ocultos[] = $b;
            }
        }

        return $ocultos;
    }

    /**
     * Empareja cada movimiento con el anterior todavía sin pareja de cantidad opuesta y creado en
     * otro momento (dos renglones del mismo alta se crean juntos y no son un reverso).
     *
     * @param  list<object>  $filas  ordenadas por id
     * @return list<array{0:int,1:int}>
     */
    private function pares(array $filas): array
    {
        $libres = [];
        $pares = [];

        foreach ($filas as $fila) {
            $cantidad = round((float) $fila->cantidad, 3);

            for ($i = count($libres) - 1; $i >= 0; $i--) {
                $previa = $libres[$i];
                if (round((float) $previa->cantidad, 3) === -$cantidad && $previa->created_at < $fila->created_at) {
                    $pares[] = [(int) $previa->id, (int) $fila->id];
                    array_splice($libres, $i, 1);

                    continue 2;
                }
            }

            $libres[] = $fila;
        }

        return $pares;
    }
}
