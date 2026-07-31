<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'permiso' => \App\Http\Middleware\VerificarPermiso::class,
        ]);

        $middleware->appendToGroup('web', \App\Http\Middleware\AplicarDuracionSesion::class);

        // Tiendanube llama estos webhooks sin cookie de sesión ni token CSRF.
        $middleware->validateCsrfTokens(except: [
            'webhooks/tiendanube/*',
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        // research.md §R5: se evalúa cada minuto; el propio comando decide si
        // corresponde ejecutar según la frecuencia configurada en pantalla.
        // withoutOverlapping() es una segunda red de seguridad sobre el
        // Cache::lock de SincronizadorOrdenes (portable a hosting compartido).
        $schedule->command('mercadolibre:sincronizar-ordenes')
            ->everyMinute()
            ->withoutOverlapping();

        // spec 013, research.md §R4: se declara DESPUÉS del de órdenes en el mismo
        // closure para que, en cada tick de schedule:run, el stock que se empuja ya
        // contemple las órdenes recién traídas — sin invocación cruzada entre comandos.
        $schedule->command('mercadolibre:sincronizar-stock')
            ->everyMinute()
            ->withoutOverlapping();

        // spec 017: mismo mecanismo de portabilidad — se evalúa cada minuto, el
        // propio comando decide si corresponde según la frecuencia configurada.
        $schedule->command('tiendanube:sincronizar-ordenes')
            ->everyMinute()
            ->withoutOverlapping();

        // spec 018, research.md §R4: DESPUÉS del de órdenes de Tiendanube en el
        // mismo closure, mismo motivo que el de Mercado Libre arriba.
        $schedule->command('tiendanube:sincronizar-stock')
            ->everyMinute()
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
