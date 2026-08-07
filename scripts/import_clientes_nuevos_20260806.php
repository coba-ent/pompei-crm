<?php
/**
 * Import incremental de clientes NUEVOS desde el re-export del 06/08/2026
 * ("Listado de Clientes 06-08-2026 0016 Hs.xlsx"), comparado contra los
 * 19.257 clientes ya importados el 05/08/2026.
 *
 * Se detectaron 108 filas nuevas (Id de origen 19228-19335, todas al final
 * del archivo, sin match de nombre exacto contra la base actual) y se
 * volcaron a CSV en /root/clientes_nuevos_import.csv. Mismo mapeo de
 * columnas que scripts/import_clientes_masivo.php.
 *
 * Corre via `php artisan tinker < scripts/import_clientes_nuevos_20260806.php`
 * en el VPS.
 */

$csvPath = '/root/clientes_nuevos_import.csv';

$condicionesIva = \App\Models\CondicionIva::pluck('id', 'nombre');
$noCategorizadoId = $condicionesIva['No Categorizado'] ?? null;
if ($noCategorizadoId === null) {
    throw new \Exception('No existe condicion de IVA "No Categorizado"');
}

$fh = fopen($csvPath, 'r');
$header = fgetcsv($fh, 0, ',', '"', '\\');

$creados = 0;
$sinCondicionIva = [];
$erroresFila = [];
$lineaCsv = 1;
$batch = [];

\Illuminate\Support\Facades\DB::beginTransaction();
try {
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        $lineaCsv++;
        if (count($row) !== count($header)) {
            $erroresFila[] = "Linea $lineaCsv: columnas desalineadas (" . count($row) . " vs " . count($header) . ")";
            continue;
        }
        $r = array_combine($header, $row);

        $condIva = trim($r['condicion_iva']);
        $condicionIvaId = $noCategorizadoId;
        if ($condIva !== '') {
            $condicionIvaId = $condicionesIva[$condIva] ?? null;
            if ($condicionIvaId === null) {
                $sinCondicionIva[] = $r['nombre'] . ' (valor: ' . $condIva . ')';
                $condicionIvaId = $noCategorizadoId;
            }
        }

        $dni = trim($r['dni']);
        $cuit = trim($r['cuit']);
        $documento = null;
        $tipoDocumento = 'CUIT';
        if ($cuit !== '') {
            $documento = preg_replace('/\D/', '', $cuit);
            $tipoDocumento = 'CUIT';
        } elseif ($dni !== '') {
            $soloDigitos = preg_replace('/\D/', '', $dni);
            $documento = $soloDigitos;
            $tipoDocumento = strlen($soloDigitos) === 11 ? 'CUIT' : 'DNI';
        }

        $saldoInicialFecha = trim($r['saldo_inicial_fecha']);
        $createdAt = trim($r['created_at']);

        $batch[] = [
            'nombre' => $r['nombre'],
            'nombre_pila' => $r['nombre_pila'] !== '' ? $r['nombre_pila'] : null,
            'apellido' => $r['apellido'] !== '' ? $r['apellido'] : null,
            'apodo_ml' => $r['apodo_ml'] !== '' ? $r['apodo_ml'] : null,
            'pagina_web' => $r['pagina_web'] !== '' ? $r['pagina_web'] : null,
            'email' => $r['email'] !== '' ? $r['email'] : null,
            'telefono' => $r['telefono'] !== '' ? $r['telefono'] : null,
            'telefono_celular' => $r['telefono_celular'] !== '' ? $r['telefono_celular'] : null,
            'domicilio' => $r['domicilio'] !== '' ? $r['domicilio'] : null,
            'localidad' => $r['localidad'] !== '' ? $r['localidad'] : null,
            'provincia' => $r['provincia'] !== '' ? $r['provincia'] : null,
            'cp' => null,
            'nota' => $r['nota'] !== '' ? $r['nota'] : null,
            'cuit' => $documento,
            'condicion_iva_id' => $condicionIvaId,
            'tipo_comprobante_defecto' => null,
            'domicilio_fiscal' => $r['domicilio_fiscal'] !== '' ? $r['domicilio_fiscal'] : null,
            'localidad_fiscal' => $r['localidad_fiscal'] !== '' ? $r['localidad_fiscal'] : null,
            'provincia_fiscal' => $r['provincia_fiscal'] !== '' ? $r['provincia_fiscal'] : null,
            'cp_fiscal' => $r['cp_fiscal'] !== '' ? $r['cp_fiscal'] : null,
            'telefono_fiscal' => null,
            'telefono_celular_fiscal' => null,
            'categoria_id' => null,
            'lista_precio_id' => null,
            'descuento_general_pct' => null,
            'nota_cliente' => null,
            'razon_social' => $r['razon_social'] !== '' ? $r['razon_social'] : null,
            'tipo_documento' => $tipoDocumento,
            'saldo_inicial' => (float) $r['saldo_inicial'],
            'saldo_inicial_fecha' => $saldoInicialFecha !== '' ? $saldoInicialFecha : null,
            'campos_personalizados' => null,
            'activo' => 1,
            'created_at' => $createdAt !== '' ? $createdAt : now(),
            'updated_at' => now(),
        ];

        $creados++;
    }

    if ($batch) {
        \Illuminate\Support\Facades\DB::table('clientes')->insert($batch);
    }

    \Illuminate\Support\Facades\DB::commit();
} catch (\Throwable $e) {
    \Illuminate\Support\Facades\DB::rollBack();
    fclose($fh);
    echo "ERROR, rollback completo: " . $e->getMessage() . "\n";
    throw $e;
}

fclose($fh);

echo "=== RESULTADO ===\n";
echo "Clientes creados: $creados\n";
echo "Filas con condicion de IVA sin match (" . count($sinCondicionIva) . "):\n";
foreach ($sinCondicionIva as $s) {
    echo "  - $s\n";
}
echo "Errores de fila (" . count($erroresFila) . "):\n";
foreach ($erroresFila as $e) {
    echo "  - $e\n";
}
echo "Total clientes en BD ahora: " . \App\Models\Cliente::count() . "\n";
