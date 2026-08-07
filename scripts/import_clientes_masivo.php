<?php
/**
 * Import masivo de clientes desde CSV (public/imports/clientes_import.csv,
 * subido como /root/clientes_import.csv en el VPS), acordado con el usuario
 * el 05/08/2026.
 *
 * Reglas acordadas:
 * - Se importan las 19.256 filas TAL CUAL, sin fusionar ni borrar duplicados
 *   (ni los 3 "NO USAR"/"BORRAR", ni los 157 CUIT repetidos con nombre
 *   distinto, ni los nombres exactos duplicados). La limpieza de clientes
 *   duplicados se hace mas adelante, DESPUES de importar las ventas
 *   (que en el Excel de ventas asocian por nombre, no por Id).
 * - El Id del Excel NO se preserva: se detecto que clientes ya tiene datos
 *   reales creados por sync de ML/TN (cron activo) despues de la limpieza,
 *   asi que forzar los ids viejos choca contra filas reales. Como las
 *   ventas del Excel matchean por NOMBRE (no por Id, segun el usuario),
 *   se deja que MySQL asigne auto_increment nuevo para cada fila.
 * - DNI/CUIT: ninguna fila tiene ambos a la vez. Si viene CUIT se guarda en
 *   `cuit` con tipo_documento='CUIT' (se le sacan los guiones). Si viene DNI
 *   se guarda en `cuit` con tipo_documento='DNI'. Si no viene ninguno,
 *   tipo_documento default 'CUIT', cuit null.
 * - condicion_iva_id: match exacto por nombre; si viene vacio se usa
 *   "No Categorizado" (id 5), decision explicita del usuario.
 * - Observaciones del Excel -> campo `nota` (nota interna), no `nota_cliente`
 *   (que es la nota de cara al cliente en ventas).
 * - Usuario de Mercado Libre -> `apodo_ml` (username, no `ml_user_id`, que es
 *   el id numerico real de la API de ML y no aplica aca).
 * - created_at se preserva de la columna "Creado" del Excel (fecha real de
 *   alta en el sistema viejo).
 * - categoria_id / lista_precio_id: no hay columna en el Excel, quedan null.
 *
 * Corre via `php artisan tinker < scripts/import_clientes_masivo.php`
 * en el VPS.
 */

$csvPath = '/root/clientes_import.csv';

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
$batchSize = 500;

function flushBatch(array &$batch): void
{
    if ($batch) {
        \Illuminate\Support\Facades\DB::table('clientes')->insert($batch);
        $batch = [];
    }
}

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
            // Algunas filas del Excel tienen un CUIT completo (con guiones,
            // 11 digitos) cargado por error en la columna DNI. Se detecta
            // por longitud tras sacar no-digitos y se guarda como CUIT.
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

        if (count($batch) >= $batchSize) {
            flushBatch($batch);
        }
    }
    flushBatch($batch);

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
foreach (array_slice($sinCondicionIva, 0, 20) as $s) {
    echo "  - $s\n";
}
echo "Errores de fila (" . count($erroresFila) . "):\n";
foreach (array_slice($erroresFila, 0, 20) as $e) {
    echo "  - $e\n";
}
echo "Total clientes en BD ahora: " . \App\Models\Cliente::count() . "\n";
