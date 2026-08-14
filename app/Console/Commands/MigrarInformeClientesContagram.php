<?php

namespace App\Console\Commands;

use App\Models\Cobro;
use App\Models\CuentaTesoreria;
use App\Models\NotaCreditoDebito;
use App\Models\Venta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Importa el informe "Cuentas Corrientes → Movimientos de Clientes" de Contagram.
 *
 * Es la fuente **buena** de los cobros, y por eso reemplaza a `migracion:cobros` en la base nueva.
 * Aquel comando sale del `Ventas c- cobro`, que trae un `Cobrado` consolidado por venta sin fecha
 * ni desglose: imputaba un único cobro por venta, fechado con la emisión de la factura y asignado
 * al primer medio de pago listado. Eso hacía envejecer mal la Cuenta Corriente —un parcial de junio
 * figuraba con la fecha de la factura de marzo— y fue la causa de fondo del descuadre que motivó
 * rehacer la base (docs/importacion_casos_a_revisar.md §5, §17).
 *
 * Este informe, en cambio, trae **una fila por movimiento**: la fecha real, el medio real y el
 * `Id Venta` explícito. Ejemplo del propio archivo de 2021: la venta 31 tiene dos cobros, $16.500
 * en Caja del Local y $476,42 en Visa. El método viejo los aplastaba en uno solo.
 *
 * Hace dos cosas, porque las dos salen del mismo archivo:
 *
 * 1. **Cobros** (`Operación = Cobro`): los crea con fecha, importe y cuenta reales.
 * 2. **NC/ND** (`Operación = Nota de Crédito|Débito`): las vincula a la venta que corrigen. El
 *    informe trae `Id Venta`, así que el vínculo es un dato, no una deducción — en el VPS hubo que
 *    reconstruirlo cruzando importes contra `Total NC`/`Total ND` y quedó al 86% (§11).
 *
 * Depende de que las ventas se hayan importado con `--preservar-id`: el `Id Venta` del informe se
 * usa como `ventas.id` directo.
 *
 * **No genera movimientos de tesorería**, igual que el resto de la migración: los saldos de las
 * cuentas salen de los extractos de `Cuentas/`. Son dos capas independientes (§17).
 *
 * Idempotente: cada cobro queda marcado con el Id de Contagram en `nota` y no se vuelve a crear.
 */
class MigrarInformeClientesContagram extends Command
{
    protected $signature = 'migracion:informe-clientes
        {--dir=* : Carpeta(s) con los .xlsx del informe (por defecto migracion-nueva/excel-origen/2021)}
        {--anio= : Procesar sólo los movimientos de ese año}
        {--dry-run : No escribe nada; sólo reporta}';

    protected $description = 'Importa cobros con fecha/cuenta reales y vincula las NC/ND a su venta, desde el informe de Movimientos de Clientes';

    /**
     * Medio de cobro del informe => nombre de la cuenta en el CRM, cuando no coinciden literalmente.
     *
     * El catálogo de la base usa los nombres **canónicos de la ficha** de Contagram, que llevan
     * sufijo en las cuentas de tarjeta/valores ("Visa a Cobrar"), mientras el informe usa el nombre
     * corto ("Visa"). Sin este mapeo no matchean.
     *
     * Deliberadamente **no se crea ninguna cuenta** ante un nombre desconocido: los importadores
     * viejos lo hacían y dejaron 12 cuentas duplicadas sobre las existentes —`Visa` junto a `VISA`,
     * con 3.990 cobros del lado equivocado—, que parten el saldo de una misma cuenta real en dos.
     * Si aparece un medio no contemplado, la fila se reporta y no se importa.
     */
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

    /** @var array<string,int> clave normalizada => id de cuenta */
    private array $cuentas = [];

    private array $stats = [];

    private array $problemas = [];

    private ?string $anio = null;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->anio = $this->option('anio') ?: null;
        $dirs = $this->option('dir') ?: [base_path('migracion-nueva/excel-origen/2021')];

        $archivos = [];
        foreach ($dirs as $dir) {
            $archivos = array_merge($archivos, glob(rtrim($dir, '/\\').'/*.xlsx') ?: []);
        }

        if ($archivos === []) {
            $this->error('No hay archivos .xlsx en: '.implode(', ', $dirs));

            return self::FAILURE;
        }

        $this->info($dryRun ? '— DRY RUN: no se escribe nada —' : '— IMPORTANDO INFORME DE CLIENTES —');
        $this->line(count($archivos).' archivo(s)'.($this->anio ? ", filtrando {$this->anio}" : ''));

        $this->cargarCuentas();

        $this->stats = array_fill_keys([
            'cobros_creados', 'cobros_ya_estaban', 'cobros_sin_venta', 'cobros_sin_cuenta',
            'retenciones_creadas', 'retenciones_ya_estaban',
            'notas_vinculadas', 'notas_ya_vinculadas', 'notas_sin_venta', 'notas_no_encontradas',
            'filas_ignoradas',
        ], 0);

        $montoCobros = 0.0;
        $montoRetenciones = 0.0;
        $vistos = [];

        foreach ($archivos as $archivo) {
            foreach ($this->filas($archivo) as $fila) {
                // Los rangos de exportación se solapan entre tandas, así que el mismo movimiento
                // puede venir en dos archivos y hay que descartar la repetición.
                //
                // **El Id solo no alcanza como clave**: el Id de Contagram no identifica al
                // movimiento. En 2021 el cobro 1239 son dos cobros distintos —$22.348,00 a la venta
                // 1226 y $1.515,89 a la 1227— y deduplicar por Id se comía el segundo. Es el mismo
                // fenómeno ya medido en tesorería, donde el Id repite en 22.823 de 48.222 filas.
                $clave = implode(':', [$fila['operacion'], $fila['id'], $fila['venta_id'],
                    number_format($fila['cobrado'], 2, '.', ''), $fila['fecha']]);

                if (isset($vistos[$clave])) {
                    continue;
                }
                $vistos[$clave] = true;

                match (true) {
                    $fila['operacion'] === 'Cobro' => $montoCobros += $this->cobro($fila, $dryRun),
                    str_starts_with($fila['operacion'], 'Nota de') => $this->nota($fila, $dryRun),
                    // Las retenciones cancelan factura igual que un cobro, pero no entran a ninguna
                    // caja: el cliente retiene la plata y la deposita al fisco. Contagram las carga
                    // aparte ("Agregar Retención" en la ficha de la venta) y por eso no aparecen en
                    // el informe filtrado por `Operación = Cobro`. Sin ellas la factura queda
                    // parcialmente impaga aunque en Contagram esté saldada.
                    str_starts_with($fila['operacion'], 'Retención') => $montoRetenciones += $this->cobro($fila, $dryRun, true),
                    default => $this->stats['filas_ignoradas']++,
                };
            }
        }

        $this->reportar($montoCobros, $montoRetenciones, $dryRun);

        return self::SUCCESS;
    }

    /** Lee un archivo del informe y devuelve sus filas ya normalizadas. */
    private function filas(string $archivo): iterable
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $hoja = $reader->load($archivo)->getActiveSheet();
        $ultima = $hoja->getHighestRow();

        $encabezado = [];
        foreach ($hoja->getRowIterator(1, 1) as $fila) {
            foreach ($fila->getCellIterator() as $celda) {
                $v = $celda->getValue();
                // Las celdas vacías del encabezado no pueden ir a array_flip. Además hay que
                // tolerarlas porque la carpeta del año mezcla informes de distinta forma
                // (compras, gastos, extractos de cuenta) y acá sólo interesan los de clientes.
                $encabezado[] = is_scalar($v) ? (string) $v : '';
            }
        }
        $col = [];
        foreach ($encabezado as $pos => $nombre) {
            if ($nombre !== '' && ! isset($col[$nombre])) {
                $col[$nombre] = $pos;
            }
        }

        foreach (['Id', 'Emisión', 'Operación', 'Medio de Cobro', 'Id Venta', 'Total Venta', 'Cobrado'] as $requerida) {
            if (! isset($col[$requerida])) {
                // Silencioso: no es un problema, es un archivo de otra cosa en la misma carpeta.
                return;
            }
        }

        $valor = fn (int $c, int $r) => $hoja->getCellByColumnAndRow($col[$c] ?? 0, $r)->getValue();

        for ($r = 2; $r <= $ultima; $r++) {
            $id = $hoja->getCellByColumnAndRow($col['Id'] + 1, $r)->getValue();

            if (! is_numeric($id)) {
                continue;
            }

            $emision = $hoja->getCellByColumnAndRow($col['Emisión'] + 1, $r)->getValue();
            $fecha = is_numeric($emision)
                ? ExcelDate::excelToDateTimeObject($emision)->format('Y-m-d')
                : null;

            // El informe grande cubre los 6 años en un solo archivo: sin este filtro, importar un
            // año arrastraría movimientos de ventas que todavía no existen.
            if ($this->anio !== null && substr((string) $fecha, 0, 4) !== $this->anio) {
                continue;
            }

            yield [
                'id' => (int) $id,
                'fecha' => $fecha,
                'operacion' => trim((string) $hoja->getCellByColumnAndRow($col['Operación'] + 1, $r)->getValue()),
                'medio' => trim((string) $hoja->getCellByColumnAndRow($col['Medio de Cobro'] + 1, $r)->getValue()),
                'venta_id' => (int) $hoja->getCellByColumnAndRow($col['Id Venta'] + 1, $r)->getValue(),
                'total' => (float) $hoja->getCellByColumnAndRow($col['Total Venta'] + 1, $r)->getValue(),
                'cobrado' => (float) $hoja->getCellByColumnAndRow($col['Cobrado'] + 1, $r)->getValue(),
                'archivo' => basename($archivo),
            ];
        }
    }

    /**
     * Crea el cobro con la fecha, el importe y la cuenta reales. Devuelve el monto imputado.
     *
     * Con `$esRetencion` la cuenta no sale del `Medio de Cobro` —que en esas filas viene vacío—
     * sino de la cuenta `Retenciones`, que es como las modela Contagram.
     */
    private function cobro(array $f, bool $dryRun, bool $esRetencion = false): float
    {
        if (abs($f['cobrado']) < 0.005) {
            $this->stats['filas_ignoradas']++;

            return 0.0;
        }

        // `ventas.id` es el Id de Contagram (import con --preservar-id), así que el vínculo es directo.
        if (! Venta::whereKey($f['venta_id'])->exists()) {
            $this->stats['cobros_sin_venta']++;
            $this->problemas[] = "Cobro {$f['id']}: no existe la venta {$f['venta_id']} ({$f['archivo']})";

            return 0.0;
        }

        $cuentaId = $esRetencion ? $this->cuenta('Retenciones') : $this->cuenta($f['medio']);

        if ($cuentaId === null) {
            $this->stats['cobros_sin_cuenta']++;
            $this->problemas[] = "Cobro {$f['id']}: medio \"{$f['medio']}\" sin cuenta en el CRM";

            return 0.0;
        }

        // La marca lleva venta e importe además del Id, por lo mismo que la clave de deduplicación:
        // el Id de Contagram no identifica al cobro por sí solo (ver arriba, cobro 1239). Las
        // retenciones tienen su propia serie de Ids, así que además se distinguen por la palabra.
        $marca = $esRetencion
            ? sprintf('Migrado de Contagram (retención %d · venta %d · %s)',
                $f['id'], $f['venta_id'], number_format($f['cobrado'], 2, '.', ''))
            : sprintf('Migrado de Contagram (cobro %d · venta %d · %s)',
                $f['id'], $f['venta_id'], number_format($f['cobrado'], 2, '.', ''));

        if (Cobro::where('nota', $marca)->exists()) {
            $this->stats[$esRetencion ? 'retenciones_ya_estaban' : 'cobros_ya_estaban']++;

            return 0.0;
        }

        $this->stats[$esRetencion ? 'retenciones_creadas' : 'cobros_creados']++;

        if ($dryRun) {
            return $f['cobrado'];
        }

        DB::transaction(function () use ($f, $cuentaId, $marca) {
            $cobro = new Cobro([
                'venta_id' => $f['venta_id'],
                'fecha' => $f['fecha'],
                'cuenta_tesoreria_id' => $cuentaId,
                'monto' => $f['cobrado'],
                'nota' => $marca,
            ]);
            // El cobro ocurrió entonces, no hoy: si no, los listados por fecha de creación muestran
            // seis años de cobranzas de golpe.
            $cobro->created_at = $f['fecha'];
            $cobro->updated_at = $f['fecha'];
            $cobro->save();
        });

        return $f['cobrado'];
    }

    /**
     * Vincula la NC/ND a la venta que corrige.
     *
     * La nota ya existe: la creó `migracion:ventas` desde el export por ítem, con `venta_id` nulo
     * porque ese archivo no dice a qué comprobante aplica. Acá se completa con el `Id Venta` del
     * informe, que sí lo trae.
     */
    private function nota(array $f, bool $dryRun): void
    {
        $familia = $f['operacion'] === 'Nota de Crédito' ? 'NC' : 'ND';
        $legacy = substr((string) $f['fecha'], 0, 4)."-{$familia}-{$f['id']}";

        $nota = NotaCreditoDebito::where('legacy_id', $legacy)->first();

        if ($nota === null) {
            $this->stats['notas_no_encontradas']++;
            $this->problemas[] = "Nota {$legacy}: no está en la base (¿se importó ese año?)";

            return;
        }

        if (! Venta::whereKey($f['venta_id'])->exists()) {
            $this->stats['notas_sin_venta']++;
            $this->problemas[] = "Nota {$legacy}: no existe la venta {$f['venta_id']}";

            return;
        }

        if ((int) $nota->venta_id === $f['venta_id']) {
            $this->stats['notas_ya_vinculadas']++;

            return;
        }

        $this->stats['notas_vinculadas']++;

        if ($dryRun) {
            return;
        }

        // El importe se toma del informe: en el export por ítem las notas multi-renglón quedaron
        // cortas (se leía el total de una sola fila) y las de 2021/2022 directamente en $0,00 (§14).
        // El signo vive en `tipo`, por eso el valor absoluto.
        $nota->update([
            'venta_id' => $f['venta_id'],
            'monto' => abs($f['total']),
        ]);
    }

    private function cargarCuentas(): void
    {
        foreach (CuentaTesoreria::pluck('id', 'nombre') as $nombre => $id) {
            $this->cuentas[$this->clave($nombre)] = $id;
        }
    }

    /** Resuelve el medio de cobro a una cuenta existente. Nunca crea una nueva (ver ALIAS). */
    private function cuenta(string $medio): ?int
    {
        $k = $this->clave($medio);

        return $this->cuentas[$k]
            ?? $this->cuentas[$this->clave(self::ALIAS[$k] ?? '')]
            ?? null;
    }

    /** Espacios colapsados y minúsculas: "Juan USD  Personal" viene con doble espacio. */
    private function clave(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    }

    private function reportar(float $montoCobros, float $montoRetenciones, bool $dryRun): void
    {
        $this->newLine();
        $this->table(['Concepto', 'Cantidad'], collect($this->stats)
            ->map(fn ($v, $k) => [str_replace('_', ' ', $k), number_format($v)])->values()->all());

        $this->line('Monto de cobros imputado: '.number_format($montoCobros, 2));

        if (abs($montoRetenciones) > 0.005) {
            $this->line('Monto de retenciones imputado: '.number_format($montoRetenciones, 2));
        }

        if ($this->problemas !== []) {
            $this->newLine();
            $this->warn('Problemas ('.count($this->problemas).'):');
            foreach (array_slice($this->problemas, 0, 25) as $p) {
                $this->line('  '.$p);
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('DRY RUN: no se escribió nada.');
        }
    }
}
