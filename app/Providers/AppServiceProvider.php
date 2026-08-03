<?php

namespace App\Providers;

use App\Models\Compra;
use App\Models\MovimientoStock;
use App\Models\PrecioProducto;
use App\Models\User;
use App\Models\Venta;
use App\Observers\CompraObserver;
use App\Observers\MovimientoStockObserver;
use App\Observers\PrecioProductoObserver;
use App\Observers\VentaObserver;
use App\Services\MercadoLibre\Bot\GeneradorDeSugerencias;
use App\Services\MercadoLibre\Bot\GeneradorDeSugerenciasOpenAI;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use OpenAI;
use OpenAI\Contracts\ClientContract;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ClientContract::class, fn () => OpenAI::client((string) config('services.openai.api_key')));
        $this->app->bind(GeneradorDeSugerencias::class, GeneradorDeSugerenciasOpenAI::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registrarGatesDePermisos();
        Venta::observe(VentaObserver::class);
        Compra::observe(CompraObserver::class);
        MovimientoStock::observe(MovimientoStockObserver::class);
        PrecioProducto::observe(PrecioProductoObserver::class);
    }

    /** Admin pasa cualquier ability (research D2); el resto se resuelve contra los permisos del rol del usuario. */
    private function registrarGatesDePermisos(): void
    {
        Gate::before(function (User $user, string $ability) {
            return $user->tienePermiso($ability) ?: null;
        });
    }
}
