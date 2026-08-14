<?php

namespace App\Console\Commands;

use App\Models\Compra;
use App\Models\CuentaTesoreria;
use App\Models\NotaCreditoDebito;
use App\Models\Pago;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Importa el informe "Cuentas Corrientes → Movimientos de Proveedores" de Contagram.
 *
 * Es al lado de compras lo que `migracion:informe-clientes` es al lado de ventas, y por el mismo
 * motivo: el `Compras c- pago` trae un `Pagado` acumulado por comprobante, sin fecha ni desglose.
 * Con eso el importador armaba **un pago por compra fechado con la emisión**, y la Cuenta Corriente
 * de Proveedores a una fecha pasada daba cualquier cosa — se descontaban pagos que todavía no
 * habían ocurrido (docs/importacion_casos_a_revisar.md §12, §15).
 *
 * El informe trae una fila por movimiento con la fecha real, el medio real y el **`Id Compra`**
 * explícito. En el VPS este mismo archivo pasó el saldo de Proveedores de $9,8 M de diferencia a
 * conciliar al centavo.
 *
 * Hace dos cosas:
 *
 * 1. **Pagos** (`Operación = Pago`): los crea con fecha, importe y cuenta reales.
 * 2. **NC/ND de compra** (`Operación = Nota de Crédito|Débito`): las vincula a la compra que
 *    corrigen. En el VPS ese vínculo hubo que deducirlo cruzando importes contra `Total NC`/
 *    `Total ND` y quedó en 139 de 149 (§8d); acá viene como dato.
 *
 * Depende de que las compras se hayan importado con `--preservar-id` (el `Id Compra` del informe se
 * usa como `compras.id`) y de correr `migracion:compras --sin-pagos`, para no duplicar.
 *
 * **No genera movimientos de tesorería**: los saldos salen de los extractos de `Cuentas/`.
 *
 * Idempotente: cada pago queda marcado con su Id de Contagram en `nota`.
 */
class MigrarInformeProveedoresContagram extends Command
{
    protected $signature = 'migracion:informe-proveedores
        {--dir=* : Carpeta(s) con los .xlsx del informe (por defecto migracion-nueva/excel-origen/pagos)}
        {--anio= : Procesar sólo los movimientos de ese año}
        {--dry-run : No escribe nada; sólo reporta}';

    protected $description = 'Importa pagos con fecha/cuenta reales y vincula las NC/ND de compra, desde el informe de Movimientos de Proveedores';

    /** Mismo criterio que en el informe de clientes: mapear a la cuenta canónica, nunca crear. */
    private const ALIAS = [
        'visa' => 'Visa a Cobrar',
        'mastercard' => 'Mastercard a Cobrar',
        'amex' => 'AMEX',
        'payway qr' => 'PAYWAY QR a Cobrar',
        'qr' => 'PAYWAY QR a Cobrar',
        'cabal acreditaciones' => 'Cabal Acreditaciones a Cobrar',
        'cabal credicoop' => 'Cabal Credicoop a Pagar',
        'visa credicoop' => 'Visa Credicoop a Pagar',
        'nulo' => 'Nulo a Cobrar',
        'galicia' => 'Banco Galicia',
        'usd online' => 'USD Online',
        'usd local' => 'Juan USD Personal',
    ];

    /** @var array<string,int> */
    private array $cuentas = [];

    private array $stats = [];

    private array $problemas = [];

    private ?string $anio = null;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->anio = $this->option('anio') ?: null;
        $dirs = $this->option('dir') ?: [base_path('migracion-nueva/excel-origen/pagos')];

        $archivos = [];
        foreach ($dirs as $dir) {
            $archivos = array_merge($archivos, glob(rtrim($dir, '/\\').'/*.xlsx') ?: []);
        }

        if ($archivos === []) {
            $this->error('No hay archivos .xlsx en: '.implode(', ', $dirs));

            return self::FAILURE;
        }

        $this->info($dryRun ? '— DRY RUN: no se escribe nada —' : '— IMPORTANDO INFORME DE PROVEEDORES —');
        $this->line(count($archivos).' archivo(s)'.($this->anio ? ", filtrando {$this->anio}" : ''));

        foreach (CuentaTesoreria::pluck('id', 'nombre') as $nombre => $id) {
            $this->cuentas[$this->clave($nombre)] = $id;
        }

        $this->stats = array_fill_keys([
            'pagos_creados', 'pagos_ya_estaban', 'pagos_sin_compra', 'pagos_sin_cuenta',
            'notas_vinculadas', 'notas_ya_vinculadas', 'notas_sin_compra', 'notas_no_encontradas',
            'filas_ignoradas',
        ], 0);

        $monto = 0.0;
        $vistos = [];

        foreach ($archivos as $archivo) {
            foreach ($this->filas($archivo) as $f) {
                // El Id de Contagram no identifica al movimiento por sí solo (ver el detalle en
                // MigrarInformeClientesContagram), así que la clave lleva compra, importe y fecha.
                $clave = implode(':', [$f['operacion'], $f['id'], $f['compra_id'],
                    number_format($f['pagado'], 2, '.', ''), $f['fecha']]);

                if (isset($vistos[$clave])) {
                    continue;
                }
                $vistos[$clave] = true;

                match (true) {
                    $f['operacion'] === 'Pago' => $monto += $this->pago($f, $dryRun),
                    str_starts_with($f['operacion'], 'Nota de') => $this->nota($f, $dryRun),
                    default => $this->stats['filas_ignoradas']++,
                };
            }
        }

        $this->newLine();
        $this->table(['Concepto', 'Cantidad'], collect($this->stats)
            ->map(fn ($v, $k) => [str_replace('_', ' ', $k), number_format($v)])->values()->all());
        $this->line('Monto de pagos imputado: '.number_format($monto, 2));

        if ($this->problemas !== []) {
            $this->newLine();
            $this->warn('Problemas ('.count($this->problemas).'):');
            foreach (array_slice($this->problemas, 0, 25) as $p) {
                $this->line('  '.$p);
            }
        }

        if ($dryRun) {
            $this->info('DRY RUN: no se escribió nada.');
        }

        return self::SUCCESS;
    }

    private function filas(string $archivo): iterable
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $hoja = $reader->load($archivo)->getActiveSheet();
        $ultima = $hoja->getHighestRow();

        $encabezado = [];
        foreach ($hoja->getRowIterator(1, 1) as $fila) {
            foreach ($fila->getCellIterator() as $celda) {
                $encabezado[] = $celda->getValue();
            }
        }
        $col = array_flip($encabezado);

        foreach (['Id', 'Emisión', 'Operación', 'Medio de Pago', 'Id Compra', 'Total compra', 'Pagado'] as $req) {
            if (! isset($col[$req])) {
                $this->problemas[] = basename($archivo).": falta la columna \"{$req}\", se omite el archivo";

                return;
            }
        }

        for ($r = 2; $r <= $ultima; $r++) {
            $id = $hoja->getCellByColumnAndRow($col['Id'] + 1, $r)->getValue();

            if (! is_numeric($id)) {
                continue;
            }

            $emision = $hoja->getCellByColumnAndRow($col['Emisión'] + 1, $r)->getValue();
            $fecha = is_numeric($emision)
                ? ExcelDate::excelToDateTimeObject($emision)->format('Y-m-d')
                : null;

            if ($this->anio !== null && substr((string) $fecha, 0, 4) !== $this->anio) {
                continue;
            }

            yield [
                'id' => (int) $id,
                'fecha' => $fecha,
                'operacion' => trim((string) $hoja->getCellByColumnAndRow($col['Operación'] + 1, $r)->getValue()),
                'medio' => trim((string) $hoja->getCellByColumnAndRow($col['Medio de Pago'] + 1, $r)->getValue()),
                'compra_id' => (int) $hoja->getCellByColumnAndRow($col['Id Compra'] + 1, $r)->getValue(),
                'total' => (float) $hoja->getCellByColumnAndRow($col['Total compra'] + 1, $r)->getValue(),
                'pagado' => (float) $hoja->getCellByColumnAndRow($col['Pagado'] + 1, $r)->getValue(),
                'archivo' => basename($archivo),
            ];
        }
    }

    private function pago(array $f, bool $dryRun): float
    {
        if (abs($f['pagado']) < 0.005) {
            $this->stats['filas_ignoradas']++;

            return 0.0;
        }

        if (! Compra::whereKey($f['compra_id'])->exists()) {
            $this->stats['pagos_sin_compra']++;
            $this->problemas[] = "Pago {$f['id']}: no existe la compra {$f['compra_id']} ({$f['archivo']})";

            return 0.0;
        }

        $cuentaId = $this->cuenta($f['medio']);

        if ($cuentaId === null) {
            $this->stats['pagos_sin_cuenta']++;
            $this->problemas[] = "Pago {$f['id']}: medio \"{$f['medio']}\" sin cuenta en el CRM";

            return 0.0;
        }

        $marca = sprintf('Migrado de Contagram (pago %d · compra %d · %s)',
            $f['id'], $f['compra_id'], number_format(abs($f['pagado']), 2, '.', ''));

        if (Pago::where('nota', $marca)->exists()) {
            $this->stats['pagos_ya_estaban']++;

            return 0.0;
        }

        $this->stats['pagos_creados']++;

        if ($dryRun) {
            return abs($f['pagado']);
        }

        DB::transaction(function () use ($f, $cuentaId, $marca) {
            $pago = new Pago([
                'compra_id' => $f['compra_id'],
                'fecha' => $f['fecha'],
                'cuenta_tesoreria_id' => $cuentaId,
                'monto' => abs($f['pagado']),
                'nota' => $marca,
            ]);
            $pago->created_at = $f['fecha'];
            $pago->updated_at = $f['fecha'];
            $pago->save();
        });

        return abs($f['pagado']);
    }

    private function nota(array $f, bool $dryRun): void
    {
        $familia = $f['operacion'] === 'Nota de Crédito' ? 'NC' : 'ND';
        $legacy = 'COMPRA-'.substr((string) $f['fecha'], 0, 4)."-{$familia}-{$f['id']}";

        $nota = NotaCreditoDebito::where('legacy_id', $legacy)->first();

        if ($nota === null) {
            $this->stats['notas_no_encontradas']++;
            $this->problemas[] = "Nota {$legacy}: no está en la base (¿se importó ese año?)";

            return;
        }

        if (! Compra::whereKey($f['compra_id'])->exists()) {
            $this->stats['notas_sin_compra']++;
            $this->problemas[] = "Nota {$legacy}: no existe la compra {$f['compra_id']}";

            return;
        }

        if ((int) $nota->compra_id === $f['compra_id']) {
            $this->stats['notas_ya_vinculadas']++;

            return;
        }

        $this->stats['notas_vinculadas']++;

        if ($dryRun) {
            return;
        }

        // Igual que en ventas: el importe del export por ítem queda corto en las notas de varios
        // renglones, así que manda el del informe. El signo vive en `tipo`.
        $nota->update([
            'compra_id' => $f['compra_id'],
            'monto' => abs($f['total']),
        ]);
    }

    private function cuenta(string $medio): ?int
    {
        $k = $this->clave($medio);

        return $this->cuentas[$k]
            ?? $this->cuentas[$this->clave(self::ALIAS[$k] ?? '')]
            ?? null;
    }

    private function clave(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    }
}
