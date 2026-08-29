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
            'admin' => \App\Http\Middleware\SoloAdmin::class,
        ]);

        $middleware->appendToGroup('web', \App\Http\Middleware\AplicarDuracionSesion::class);

        // Tiendanube y Mercado Libre llaman estos webhooks sin cookie de sesión ni token CSRF.
        $middleware->validateCsrfTokens(except: [
            'webhooks/tiendanube/*',
            'webhooks/mercadolibre',
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

        // spec 034 (FR-016): revisión diaria del certificado fiscal ARCA.
        $schedule->command('arca:avisar-vencimiento-certificado')
            ->daily();

        // spec 050, research.md §R3: mismo mecanismo de portabilidad que arriba
        // (se evalúa cada minuto, el propio comando decide si corresponde según
        // el intervalo fijo de 24hs) — independiente de la corrida de stock, para
        // no multiplicar llamadas a la API por un dato que casi no cambia.
        $schedule->command('mercadolibre:sincronizar-tipos-publicacion')
            ->everyMinute()
            ->withoutOverlapping();

        // spec 084, US3 — chequeo diario de precios publicados contra el CRM. Es la red que faltó
        // el 25/08/2026, cuando 18 publicaciones estuvieron 30 horas un 31% por debajo de su precio
        // sin que nada avisara. Sólo lectura hacia Mercado Libre, y de madrugada porque son ~270
        // llamadas seguidas que no tienen por qué competir con la operación del día.
        $schedule->command('ml:chequear-precios')
            ->dailyAt('04:30')
            ->withoutOverlapping();

        // spec 093, US3 — limpieza de los archivos de importación vencidos (90 días por defecto)
        // y de los sueltos sin corrida. De madrugada porque borra del disco y no tiene por qué
        // competir con una importación en curso.
        $schedule->command('importaciones:limpiar-archivos')
            ->dailyAt('04:45')
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
