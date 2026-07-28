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
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
