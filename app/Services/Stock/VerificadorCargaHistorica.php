<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * La garantía de la spec 094: el stock actual no cambia y nada queda pendiente de sincronizar.
 *
 * Toma una foto antes de escribir y la compara después. Cualquier diferencia lanza, y como todo
 * corre dentro de una transacción, lanzar significa que no queda nada.
 *
 * No es una precaución redundante. `movimientos_stock` y `stocks` son tablas distintas y el comando
 * sólo escribe en la primera, así que el stock actual es intocable por construcción — pero esta
 * verificación es lo que convierte esa afirmación en algo comprobado en cada corrida en vez de en
 * una promesa.
 */
class VerificadorCargaHistorica
{
    /** @var array<string,string>|null producto_id:deposito_id => cantidad */
    private ?array $stockAntes = null;

    /** @var array<int,bool>|null publicación ML => estaba pendiente */
    private ?array $mlAntes = null;

    /** @var array<int,bool>|null variante Tiendanube => estaba pendiente */
    private ?array $tnAntes = null;

    public function fotografiar(): void
    {
        $this->stockAntes = $this->stock();
        $this->mlAntes = $this->pendientesMl();
        $this->tnAntes = $this->pendientesTiendanube();
    }

    /**
     * Compara contra la foto. Lanza al primer desvío.
     *
     * @throws RuntimeException
     */
    public function verificar(): void
    {
        if ($this->stockAntes === null) {
            throw new RuntimeException('Se llamó a verificar() sin haber fotografiado antes.');
        }

        $this->compararStock();
        $this->compararPendientes($this->mlAntes ?? [], $this->pendientesMl(), 'publicación de Mercado Libre');
        $this->compararPendientes($this->tnAntes ?? [], $this->pendientesTiendanube(), 'variante de Tiendanube');
    }

    private function compararStock(): void
    {
        $despues = $this->stock();

        if (count($despues) !== count($this->stockAntes)) {
            throw new RuntimeException(sprintf(
                'La cantidad de filas de stock cambió: %d antes, %d después. Se revierte.',
                count($this->stockAntes),
                count($despues)
            ));
        }

        foreach ($this->stockAntes as $clave => $cantidad) {
            $actual = $despues[$clave] ?? null;

            if ($actual !== $cantidad) {
                [$producto, $deposito] = explode(':', $clave);

                throw new RuntimeException(sprintf(
                    'El stock del producto %s en el depósito %s cambió de %s a %s. Se revierte la corrida entera.',
                    $producto,
                    $deposito,
                    $cantidad,
                    $actual ?? 'ausente'
                ));
            }
        }
    }

    /**
     * @param  array<int,bool>  $antes
     * @param  array<int,bool>  $despues
     */
    private function compararPendientes(array $antes, array $despues, string $que): void
    {
        // Sólo importan las que pasaron a pendiente por efecto de la carga. Que una deje de estarlo
        // no puede pasar acá, y si pasara no sería un daño.
        $nuevas = array_diff_key($despues, $antes);

        if ($nuevas !== []) {
            throw new RuntimeException(sprintf(
                '%d %s quedaron pendientes de sincronizar por efecto de la carga (ids: %s). '.
                'Es la señal de que los observers se dispararon. Se revierte.',
                count($nuevas),
                $que,
                implode(', ', array_slice(array_keys($nuevas), 0, 10))
            ));
        }
    }

    /** @return array<string,string> */
    private function stock(): array
    {
        $foto = [];

        foreach (DB::table('stocks')->select('producto_id', 'deposito_id', 'cantidad')->cursor() as $fila) {
            $foto["{$fila->producto_id}:{$fila->deposito_id}"] = (string) $fila->cantidad;
        }

        return $foto;
    }

    /** @return array<int,bool> */
    private function pendientesMl(): array
    {
        return DB::table('ml_publicacion_producto')
            ->where('stock_pendiente', true)
            ->pluck('id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /** @return array<int,bool> */
    private function pendientesTiendanube(): array
    {
        return DB::table('tn_variante_producto')
            ->where('stock_pendiente', true)
            ->pluck('id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }
}
