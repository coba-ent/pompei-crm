<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rescata los presupuestos **pendientes** que quedaron en la base vieja del CRM.
 *
 * Al rehacer la base desde Contagram se perdieron los presupuestos cargados a mano en el CRM: los
 * informes de Contagram no traen el vínculo presupuesto↔venta, así que no se importaron. Los
 * **aceptados** no importan —ya se convirtieron en Venta y esa Venta sí está—, pero los
 * **pendientes** son trabajo hecho que el cliente todavía espera y que no está en ningún lado.
 *
 * No es una importación: se **crean de nuevo**, con id y numeración limpios de la base nueva. Del
 * original se conserva todo lo que sea información del negocio —fecha de emisión y validez, cliente,
 * vendedor, quién lo cargó, notas, descuentos, formas de pago, ítems con su IVA— y `created_at`,
 * para que el listado los ordene en su lugar y no amontonados el día de la migración.
 *
 * Los presupuestos no afectan stock ni contabilidad (regla de negocio del proyecto), así que esto no
 * toca ningún saldo.
 */
class RecrearPresupuestosPendientes extends Command
{
    protected $signature = 'migracion:recrear-presupuestos
        {--origen=contagram : Base de datos vieja de donde leerlos}
        {--dry-run : Sólo reporta lo que haría}';

    protected $description = 'Vuelve a crear en la base nueva los presupuestos pendientes de la base vieja del CRM';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $origen = (string) $this->option('origen');

        $pendientes = DB::connection()->select(
            "SELECT * FROM `{$origen}`.presupuestos WHERE estado = 'pendiente' AND venta_id IS NULL ORDER BY created_at, id"
        );

        if ($pendientes === []) {
            $this->info('No hay presupuestos pendientes para recrear.');

            return self::SUCCESS;
        }

        // La numeración arranca donde quedó la base nueva, no donde quedó la vieja.
        $ultimo = (int) DB::table('presupuestos')->max('nro_presupuesto');
        $this->line("Pendientes en `{$origen}`: ".count($pendientes)." · la numeración sigue desde ".str_pad($ultimo + 1, 8, '0', STR_PAD_LEFT));

        $filas = [];
        $avisos = [];
        $numero = $ultimo;

        foreach ($pendientes as $viejo) {
            $numero++;

            $cliente = DB::table('clientes')->where('id', $viejo->cliente_id)->first(['id', 'nombre']);

            if (! $cliente) {
                $avisos[] = "Presupuesto {$viejo->nro_presupuesto}: el cliente {$viejo->cliente_id} no existe en la base nueva — se saltea.";
                $numero--;

                continue;
            }

            $items = DB::connection()->select(
                "SELECT * FROM `{$origen}`.presupuesto_items WHERE presupuesto_id = ? ORDER BY id", [$viejo->id]
            );

            $itemsResueltos = [];

            foreach ($items as $item) {
                $productoId = $this->resolverProducto($item, $avisos, $viejo->nro_presupuesto);
                $itemsResueltos[] = [$item, $productoId];
            }

            $filas[] = [
                str_pad($numero, 8, '0', STR_PAD_LEFT),
                $viejo->nro_presupuesto,
                $viejo->fecha_emision,
                substr($cliente->nombre, 0, 28),
                number_format((float) $viejo->total, 2),
                count($items),
            ];

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($viejo, $numero, $itemsResueltos) {
                $datos = (array) $viejo;
                unset($datos['id']);

                $datos['nro_presupuesto'] = str_pad($numero, 8, '0', STR_PAD_LEFT);
                $datos['venta_id'] = null;
                // Un token de envío viejo no tiene sentido en la base nueva: es anti-doble-submit.
                $datos['submit_token'] = null;
                $datos['updated_at'] = now();

                $nuevoId = DB::table('presupuestos')->insertGetId($datos);

                foreach ($itemsResueltos as [$item, $productoId]) {
                    $datosItem = (array) $item;
                    unset($datosItem['id']);

                    $datosItem['presupuesto_id'] = $nuevoId;
                    $datosItem['producto_id'] = $productoId;
                    $datosItem['updated_at'] = now();

                    DB::table('presupuesto_items')->insert($datosItem);
                }
            });
        }

        $this->newLine();
        $this->table(['N° nuevo', 'N° viejo', 'Emisión', 'Cliente', 'Total', 'Ítems'], $filas);

        if ($avisos !== []) {
            $this->newLine();
            $this->line('Avisos:');
            foreach ($avisos as $aviso) {
                $this->warn('  '.$aviso);
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->info('DRY RUN: no se escribió nada. Se crearían '.count($filas).' presupuestos.');

            return self::SUCCESS;
        }

        $this->info('Presupuestos recreados: '.count($filas));

        return self::SUCCESS;
    }

    /**
     * El `producto_id` viejo puede no existir en la base nueva. El código real del producto está
     * dentro de la descripción —el SKU es el id del producto en este negocio— así que se recupera de
     * ahí en vez de perder el vínculo. Si tampoco aparece, el ítem queda sin producto pero conserva
     * su descripción y su importe: mejor un renglón suelto que un presupuesto incompleto.
     *
     * @param  array<int, string>  $avisos
     */
    private function resolverProducto(object $item, array &$avisos, string $nroViejo): ?int
    {
        if ($item->producto_id === null) {
            return null;
        }

        if (DB::table('productos')->where('id', $item->producto_id)->exists()) {
            return (int) $item->producto_id;
        }

        // Todos los números de 4+ dígitos de la descripción, del más largo al más corto.
        preg_match_all('/\d{4,}/', (string) $item->descripcion, $coincidencias);
        $candidatos = collect($coincidencias[0])->map(fn ($n) => (int) $n)->unique()
            ->sortByDesc(fn ($n) => strlen((string) $n))->values();

        foreach ($candidatos as $candidato) {
            if (DB::table('productos')->where('id', $candidato)->exists()) {
                $avisos[] = "Presupuesto {$nroViejo}: producto {$item->producto_id} no existe, se vinculó al {$candidato} por el código de la descripción.";

                return $candidato;
            }
        }

        $avisos[] = "Presupuesto {$nroViejo}: producto {$item->producto_id} no existe y no se pudo resolver — el ítem queda sin producto vinculado.";

        return null;
    }
}
