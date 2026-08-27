<?php

namespace App\Console\Commands;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\PrecioProducto;
use App\Support\OrigenCambioPrecio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrige los precios de la lista de Mercado Libre que quedaron divididos por 1000 en la migración
 * del 06/08/2026.
 *
 * **Qué pasó**: la planilla traía los importes en formato argentino ("262.252,00") y esa columna se
 * leyó con el punto como separador decimal, así que "262.252,00" entró como 262,26. Afecta sólo a
 * la lista `ML`: las demás listas del mismo producto tienen el valor correcto.
 *
 * **Por qué la referencia es Tiendanube y no "ML Sugerido"**: medido sobre los 9.034 productos que
 * tienen precio en las dos listas, `ML` y `Tiendanube` son **idénticas en 8.695** y sólo difieren
 * en 193 casos sueltos; `ML Sugerido`, en cambio, es un precio distinto —más bajo— en el 100% de
 * los casos sanos. Tiendanube es el espejo de la lista ML, ML Sugerido no.
 *
 * **Por qué es seguro**: no inventa ningún precio. Sólo copia el de la lista de referencia, y
 * únicamente cuando la relación entre las dos es 1000 dentro de una tolerancia mínima; cualquier
 * otra relación es un precio legítimamente distinto y no se toca. Además exige que la lista de
 * confirmación coincida con la de referencia —sobre los 146 rotos las dos dan el mismo valor, y si
 * alguna dejara de coincidir es señal de que el caso no es este bug y hay que mirarlo a mano.
 *
 * **Efecto sobre Mercado Libre**: escribe con Eloquent a propósito, para que pase por
 * `PrecioProductoObserver` y el precio corregido se empuje a las publicaciones vinculadas. Los
 * vínculos afectados tienen hoy el precio **correcto** en Mercado Libre —la API rechazó el importe
 * absurdo en su momento—, así que el push es un no-op que además les limpia el `precio_pendiente`
 * y el `precio_error` que arrastran desde el 14/08.
 *
 * Sin `--aplicar` no escribe nada: lista lo que haría y termina.
 */
class CorregirEscalaPreciosListaMl extends Command
{
    protected $signature = 'precios:corregir-escala-ml
        {--aplicar : Escribe los cambios. Sin esta opción sólo informa}
        {--referencia=6 : Id de la lista de la que se copia el valor correcto (por defecto Tiendanube)}
        {--confirmacion=12 : Id de una segunda lista que tiene que dar el mismo valor (por defecto ML Sugerido)}
        {--tolerancia=1 : Cuánto puede alejarse de 1000 la relación entre las dos listas}';

    protected $description = 'Corrige los precios de la lista ML que quedaron divididos por 1000 en la migración';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $referencia = (int) $this->option('referencia');
        $confirmacion = (int) $this->option('confirmacion');
        $tolerancia = (float) $this->option('tolerancia');

        $lista = (int) MercadoLibreConfiguracion::actual()->lista_precio_id;

        if (! $lista) {
            $this->error('No hay lista de precios configurada para Mercado Libre.');

            return self::FAILURE;
        }

        if ($lista === $referencia) {
            $this->error('La lista de referencia no puede ser la misma que se va a corregir.');

            return self::FAILURE;
        }

        $nombre = fn (int $id) => DB::table('listas_precio')->where('id', $id)->value('nombre') ?? "#{$id}";

        $this->info(sprintf('Corregir "%s" (#%d) copiando de "%s" (#%d), confirmado contra "%s" (#%d).',
            $nombre($lista), $lista, $nombre($referencia), $referencia, $nombre($confirmacion), $confirmacion));
        $this->line($aplicar ? '— APLICANDO —' : '— sólo informa: agregá --aplicar para escribir —');
        $this->newLine();

        $candidatos = DB::table('precios_producto as ml')
            ->join('precios_producto as ref', function ($j) use ($referencia) {
                $j->on('ref.producto_id', '=', 'ml.producto_id')->where('ref.lista_precio_id', '=', $referencia);
            })
            ->join('productos as p', 'p.id', '=', 'ml.producto_id')
            ->where('ml.lista_precio_id', $lista)
            ->where('ml.precio', '>', 0)
            ->where('ref.precio', '>', 0)
            ->whereRaw('ABS(ref.precio / ml.precio - 1000) < ?', [$tolerancia])
            ->leftJoin('precios_producto as conf', function ($j) use ($confirmacion) {
                $j->on('conf.producto_id', '=', 'ml.producto_id')->where('conf.lista_precio_id', '=', $confirmacion);
            })
            ->select('ml.id', 'ml.producto_id', 'ml.precio as actual', 'ref.precio as correcto',
                'conf.precio as confirmacion', 'p.codigo', 'p.nombre',
                DB::raw("(SELECT COUNT(*) FROM ml_publicacion_producto v WHERE v.producto_id = ml.producto_id) as vinculos"))
            ->orderByDesc('ref.precio')
            ->get();

        if ($candidatos->isEmpty()) {
            $this->info('No hay ningún precio con la escala rota. Nada que hacer.');

            return self::SUCCESS;
        }

        // Los que la segunda lista no confirma se apartan y no se tocan: son casos que no responden
        // a este bug y merecen mirarse de a uno.
        $dudosos = $candidatos->filter(fn ($c) => $c->confirmacion !== null
            && abs((float) $c->confirmacion - (float) $c->correcto) > 0.02);

        $candidatos = $candidatos->reject(fn ($c) => $dudosos->contains('id', $c->id))->values();

        if ($dudosos->isNotEmpty()) {
            $this->warn(sprintf('%d apartados porque las dos listas de referencia no coinciden:', $dudosos->count()));
            foreach ($dudosos as $d) {
                $this->line(sprintf('  %-9s %-40s referencia %s   confirmación %s',
                    $d->codigo, mb_substr((string) $d->nombre, 0, 40),
                    number_format((float) $d->correcto, 2, ',', '.'),
                    number_format((float) $d->confirmacion, 2, ',', '.')));
            }
            $this->newLine();
        }

        $this->table(
            ['producto', 'código', 'nombre', 'ahora', 'quedaría', 'vínculos ML'],
            $candidatos->map(fn ($c) => [
                $c->producto_id,
                $c->codigo,
                mb_substr((string) $c->nombre, 0, 42),
                number_format((float) $c->actual, 2, ',', '.'),
                number_format((float) $c->correcto, 2, ',', '.'),
                $c->vinculos ?: '',
            ])->all()
        );

        $vinculados = $candidatos->where('vinculos', '>', 0);

        $this->info(sprintf('%d precios a corregir, %d de ellos con publicación en Mercado Libre.',
            $candidatos->count(), $vinculados->count()));

        if (! $aplicar) {
            $this->newLine();
            $this->comment('Nada escrito. Volvé a correrlo con --aplicar cuando quieras hacerlo.');

            return self::SUCCESS;
        }

        $corregidos = 0;
        $barra = $this->output->createProgressBar($candidatos->count());
        $barra->start();

        // El origen es lo que queda registrado en la auditoría de cada cambio (spec 074): sin
        // declararlo, los 146 eventos saldrían rotulados "origen no identificado".
        OrigenCambioPrecio::durante(OrigenCambioPrecio::EDICION_MASIVA, function () use ($candidatos, $barra, &$corregidos) {
            foreach ($candidatos as $c) {
                $precio = PrecioProducto::find($c->id);

                if (! $precio) {
                    $barra->advance();

                    continue;
                }

                // Se relee y se vuelve a validar contra la fila viva: entre el SELECT de arriba y
                // este punto alguien pudo haber tocado el precio desde la pantalla.
                if (abs((float) $precio->precio - (float) $c->actual) > 0.005) {
                    $this->newLine();
                    $this->warn("Salteado: el precio #{$c->id} cambió mientras corría el comando.");
                    $barra->advance();

                    continue;
                }

                $precio->update(['precio' => (float) $c->correcto]);
                $corregidos++;
                $barra->advance();
            }
        });

        $barra->finish();
        $this->newLine(2);
        $this->info("Listo: {$corregidos} precios corregidos.");

        if ($vinculados->isNotEmpty()) {
            $this->line('Los vinculados dispararon su envío a Mercado Libre. Revisá el estado en la pantalla de la integración.');
        }

        return self::SUCCESS;
    }
}
