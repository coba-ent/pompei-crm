<?php

namespace App\Console\Commands;

use App\Models\Categoria;
use App\Models\Gasto;
use App\Services\Migracion\CuentasDeTesoreria;
use App\Services\Migracion\GastosContagram;
use App\Services\Migracion\LectorExcelContagram;
use Illuminate\Console\Command;

/**
 * Migra los 9.394 gastos históricos de Contagram (2021 → 05/08/2026).
 *
 * **No genera movimientos de tesorería**, igual que cobros y pagos: los 9.274 movimientos de
 * operación "Gasto" ya entraron por `migracion:tesoreria` desde `Cuentas/`, que es la fuente
 * completa. Crearlos otra vez contaría cada gasto dos veces en la caja.
 *
 * Las categorías se reconstruyen con su jerarquía real (Categoría → Subcategoría), que en los
 * archivos 2021-2024 sólo se puede obtener arrastrando la fila separadora del informe: hay 6
 * nombres de subcategoría que existen bajo más de un padre y afectan a 607 gastos.
 *
 * Idempotente por `legacy_id` = `GASTO-{año}-{Id}`.
 */
class MigrarGastosContagram extends Command
{
    protected $signature = 'migracion:gastos
        {--dry-run : No escribe nada; sólo reporta}
        {--anio= : Procesar un solo año}';

    protected $description = 'Migra los gastos históricos de Contagram (no toca tesorería)';

    private CuentasDeTesoreria $cuentas;

    /** @var array<string,int> "Categoría|Subcategoría" => id */
    private array $categorias = [];

    public function handle(LectorExcelContagram $lector): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $servicio = new GastosContagram($lector, public_path('imports'));
        $anios = $this->option('anio') ? [$this->option('anio')] : GastosContagram::ANIOS;

        $this->info($dryRun ? '— DRY RUN: no se escribe nada —' : '— IMPORTANDO GASTOS —');
        $this->cuentas = new CuentasDeTesoreria();
        $this->precargarCategorias();

        $stats = array_fill_keys(['creados', 'ya_existian', 'fuera_de_corte', 'sin_fecha',
            'categorias_creadas', 'sin_categoria'], 0);
        $monto = 0.0;
        $porAnio = [];

        foreach ($anios as $anio) {
            $gastos = $servicio->delAnio($anio);
            $montoAnio = 0.0;
            $nAnio = 0;

            foreach ($gastos as $g) {
                if ($g['fecha'] === null) {
                    $stats['sin_fecha']++;

                    continue;
                }
                if (! $servicio->dentroDelCorte($g['fecha'])) {
                    $stats['fuera_de_corte']++;

                    continue;
                }
                if (Gasto::where('legacy_id', $g['legacy_id'])->exists()) {
                    $stats['ya_existian']++;

                    continue;
                }

                $stats['creados']++;
                $monto += $g['monto'];
                $montoAnio += $g['monto'];
                $nAnio++;

                $categoriaId = $this->categoria($g['categoria'], $g['subcategoria'], $dryRun, $stats);
                if ($categoriaId === null && ! $dryRun) {
                    $stats['sin_categoria']++;

                    continue;   // categoria_id es NOT NULL: sin categoría no se puede guardar
                }

                if ($dryRun) {
                    continue;
                }

                $gasto = new Gasto([
                    'legacy_id' => $g['legacy_id'],
                    'fecha' => $g['fecha'],
                    'monto' => $g['monto'],
                    'categoria_id' => $categoriaId,
                    'cuenta_tesoreria_id' => $this->cuentas->resolver($g['medio_pago']),
                    'descripcion' => $g['descripcion'],
                    'pendiente' => $g['pendiente'],
                ]);
                // Mismo criterio que ventas y compras: el gasto ocurrió entonces, no hoy.
                $gasto->created_at = $g['fecha'];
                $gasto->updated_at = $g['fecha'];
                $gasto->save();
            }

            $porAnio[] = [$anio, number_format($nAnio), number_format($montoAnio, 2)];
        }

        $this->newLine();
        $this->table(['Año', 'Gastos', 'Monto'], $porAnio);
        $this->table(['Concepto', 'Cantidad'], collect($stats)
            ->map(fn ($v, $k) => [str_replace('_', ' ', $k), number_format($v)])->values()->all());
        $this->table(['Importe', 'Migrado', 'Control (plan §6)'],
            [['Gastos', number_format($monto, 2), '9.394 gastos']]);

        if ($dryRun) {
            $this->info('DRY RUN: no se escribió nada.');
        }

        return self::SUCCESS;
    }

    private function precargarCategorias(): void
    {
        foreach (Categoria::where('tipo', 'gasto')->get(['id', 'nombre', 'categoria_padre_id']) as $c) {
            $padre = $c->categoria_padre_id
                ? Categoria::whereKey($c->categoria_padre_id)->value('nombre')
                : null;
            $this->categorias[($padre ?? $c->nombre).'|'.$c->nombre] = $c->id;
        }
    }

    /**
     * Id de la subcategoría, creando la jerarquía Categoría → Subcategoría si falta.
     *
     * La clave del caché lleva **padre + hijo** porque el nombre de la subcategoría no es único:
     * `Alquiler`, `ABL`, `Aysa`, `Edenor` y `Personal Flow` existen bajo *Juan Personal* y bajo
     * *Oficina Pompei* a la vez. Cachear sólo por nombre mandaría 607 gastos a la categoría
     * equivocada.
     */
    private function categoria(?string $categoria, ?string $subcategoria, bool $dryRun, array &$stats): ?int
    {
        $categoria = $categoria !== null && $categoria !== '' ? $categoria : null;
        $subcategoria = $subcategoria !== null && $subcategoria !== '' ? $subcategoria : $categoria;

        if ($categoria === null && $subcategoria === null) {
            return null;
        }
        $categoria ??= $subcategoria;

        $clave = $categoria.'|'.$subcategoria;
        if (array_key_exists($clave, $this->categorias)) {
            return $this->categorias[$clave];
        }

        $stats['categorias_creadas']++;
        if ($dryRun) {
            return $this->categorias[$clave] = null;
        }

        $padre = $this->categorias[$categoria.'|'.$categoria]
            ??= Categoria::firstOrCreate(
                ['tipo' => 'gasto', 'nombre' => $categoria, 'categoria_padre_id' => null]
            )->id;

        if ($subcategoria === $categoria) {
            return $this->categorias[$clave] = $padre;
        }

        return $this->categorias[$clave] = Categoria::firstOrCreate(
            ['tipo' => 'gasto', 'nombre' => $subcategoria, 'categoria_padre_id' => $padre]
        )->id;
    }
}
