<?php

/**
 * SÓLO LECTURA — stock del CRM contra un export de Contagram.
 *
 *   php scripts/stock/comparar_contagram.php "/ruta/Listado de Productos y Servicios ... .xlsx"
 *
 * Corre en LOCAL (el export está en la máquina del usuario), apuntando la base a la copia
 * que corresponda, o se sube el .xlsx al VPS y se corre allá.
 *
 * DOS TRAMPAS del export, las dos ya pisadas:
 *  1. Contagram RENUMERÓ los productos. Si la hoja trae la columna `ID VIEJOS`, ésa es la
 *     clave — el `Id` apunta a otro producto. Cruzar por `Id` daba 115 diferencias falsas.
 *  2. El export es una foto: toda venta posterior a su hora aparece como diferencia. La hora
 *     del nombre del archivo es local (-03); los `created_at` del CRM están en UTC.
 */

require __DIR__.'/_comun.php';

use App\Services\Migracion\LectorExcelContagram;
use Illuminate\Support\Facades\DB;

$archivo = $argv[1] ?? null;
if (! $archivo || ! is_file($archivo)) {
    echo "Uso: php scripts/stock/comparar_contagram.php <archivo.xlsx>\n";
    exit(1);
}

$lector = new LectorExcelContagram();
$hoja = $lector->leer($archivo);

$clave = in_array('ID VIEJOS', $hoja['header'], true) ? 'ID VIEJOS' : 'Id';
printf("Archivo : %s\nClave   : %s%s\n", basename($archivo), $clave,
    $clave === 'ID VIEJOS' ? '  (la hoja trae ids renumerados)' : '');

$depMl = depositoMlId();
$depFull = depositoFullId();
$columnas = array_filter(['Local' => $depMl, 'Full' => $depFull]);

$enCrm = [];
foreach (DB::table('stocks')->whereIn('deposito_id', array_values($columnas))->get() as $s) {
    $enCrm[$s->producto_id][$s->deposito_id] = (float) $s->cantidad;
}

$coinciden = 0;
$difieren = [];
$servicios = 0;
$noEnCrm = 0;

foreach ($hoja['filas'] as $f) {
    $id = $lector->texto($f[$clave] ?? null);
    if ($id === null || $id === '') {
        continue;
    }
    if (mb_strtolower($lector->texto($f['Tipo'] ?? null) ?? '') === 'servicio') {
        $servicios++;

        continue;
    }
    if (! isset($enCrm[(int) $id])) {
        $noEnCrm++;

        continue;
    }

    foreach ($columnas as $columna => $depositoId) {
        if (! array_key_exists($columna, $f)) {
            continue;
        }
        $deLaHoja = (float) ($lector->numero($f[$columna] ?? null) ?? 0);
        $delCrm = $enCrm[(int) $id][$depositoId] ?? 0.0;

        if (abs($deLaHoja - $delCrm) < 0.005) {
            $coinciden++;

            continue;
        }

        $difieren[] = sprintf('  %-7d %-42s %-6s CRM %8s   Contagram %8s   (%+s)',
            $id, mb_substr($lector->texto($f['Nombre'] ?? null) ?? '', 0, 42), $columna,
            number_format($delCrm, 0), number_format($deLaHoja, 0), number_format($deLaHoja - $delCrm, 0));
    }
}

titulo('CRM vs Contagram');
printf("  coinciden                 : %d\n", $coinciden);
printf("  DIFIEREN                  : %d\n", count($difieren));
printf("  servicios (se saltean)    : %d\n", $servicios);
printf("  en la hoja y no en el CRM : %d\n", $noEnCrm);

if ($difieren !== []) {
    titulo('Diferencias');
    foreach ($difieren as $d) {
        echo "$d\n";
    }
    echo "\n  Antes de tomarlas por buenas, descontar los movimientos posteriores al export:\n";
    echo "  SELECT * FROM movimientos_stock WHERE created_at >= '<hora del export EN UTC>';\n";
}
