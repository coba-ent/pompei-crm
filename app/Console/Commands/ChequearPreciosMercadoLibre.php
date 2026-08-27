<?php

namespace App\Console\Commands;

use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Services\MercadoLibre\ChequeoPreciosPublicados;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Compara los precios publicados en Mercado Libre contra los del CRM (spec 084, US3).
 *
 * Corrida diaria, más ejecución a demanda desde el monitoreo. **Sólo lectura hacia Mercado Libre.**
 *
 * `--refrescar-publicado` además guarda en la base el precio que ve. Es lo que hace el **backfill
 * previo a activar el corte** (research.md Decisión 5): sin poblar `precio_publicado`, el corte
 * retendría todas las publicaciones el primer día, porque no tendría contra qué comparar.
 */
class ChequearPreciosMercadoLibre extends Command
{
    /** El resultado de la última corrida, que es lo que muestra el panel de monitoreo. */
    public const CACHE_KEY = 'ml:chequeo_precios:ultimo';

    protected $signature = 'ml:chequear-precios
        {--refrescar-publicado : Guarda el precio que ve como referencia del corte (backfill)}
        {--json : Devuelve el resultado crudo en JSON}';

    protected $description = 'Compara los precios publicados en Mercado Libre contra los del CRM';

    public function handle(ChequeoPreciosPublicados $chequeo): int
    {
        $configuracion = MercadoLibreConfiguracion::actual();

        if (! $configuracion->lista_precio_id) {
            $this->error('No hay Lista de Precios configurada para Mercado Libre.');

            return self::FAILURE;
        }

        $resultado = $chequeo->ejecutar(refrescarPublicado: (bool) $this->option('refrescar-publicado'));

        Cache::forever(self::CACHE_KEY, $resultado);

        if ($this->option('json')) {
            $this->line(json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $r = $resultado['resumen'];

        $this->info(sprintf(
            'Verificadas %d — coinciden %d, difieren %d, retenidas %d, no verificables %d, en promoción %d.',
            $r['verificadas'], $r['coinciden'], $r['difieren'], $r['retenidas'], $r['no_verificables'],
            $r['en_promocion'] ?? 0,
        ));

        if (($resultado['promociones'] ?? []) !== []) {
            $this->newLine();
            $this->line('Publicaciones con promoción activa (no son desfasajes: el precio de lista coincide):');
            foreach ($resultado['promociones'] as $p) {
                $this->line(sprintf('  %-14s %-40s  lista %s → se cobra %s  (−%s%%)',
                    $p['ml_item_id'], mb_substr((string) $p['producto'], 0, 40),
                    number_format($p['precio_lista'], 2, ',', '.'),
                    number_format($p['precio_con_descuento'], 2, ',', '.'),
                    number_format($p['descuento_pct'], 2, ',', '.')));
            }
        }

        if ($resultado['diferencias'] !== []) {
            $this->newLine();
            $this->warn('Publicaciones cuyo precio no coincide:');
            $this->table(
                ['publicación', 'producto', 'tipo', 'en el CRM', 'en Mercado Libre', 'diferencia'],
                array_map(fn ($d) => [
                    $d['ml_item_id'],
                    mb_substr((string) $d['producto'], 0, 40),
                    $d['tipo_publicacion'],
                    $d['precio_crm'] === null ? 'sin precio' : number_format($d['precio_crm'], 2, ',', '.'),
                    number_format($d['precio_publicado'], 2, ',', '.'),
                    $d['diferencia'] === null ? '-' : number_format($d['diferencia'], 2, ',', '.'),
                ], $resultado['diferencias']),
            );
        }

        foreach ([
            'premium_sin_precio_en_su_lista' => 'Publicaciones Premium sin precio en la lista Premium (cotizan por la general, ~31% más barato):',
            'sin_tipo_de_publicacion' => 'Vínculos sin tipo de publicación conocido:',
        ] as $clave => $titulo) {
            if ($resultado['advertencias'][$clave] === []) {
                continue;
            }

            $this->newLine();
            $this->warn($titulo);
            foreach ($resultado['advertencias'][$clave] as $a) {
                $this->line("  {$a['ml_item_id']}  {$a['producto']}");
            }
        }

        if ($resultado['no_verificables'] !== []) {
            $this->newLine();
            $this->warn(sprintf('%d publicaciones no se pudieron verificar.', count($resultado['no_verificables'])));
        }

        return self::SUCCESS;
    }
}
