<?php

namespace App\Providers;

use App\Models\Compra;
use App\Models\User;
use App\Models\Venta;
use App\Observers\CompraObserver;
use App\Observers\VentaObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registrarGatesDePermisos();
        Venta::observe(VentaObserver::class);
        Compra::observe(CompraObserver::class);
    }

    /** Admin pasa cualquier ability (research D2); el resto se resuelve contra los permisos del rol del usuario. */
    private function registrarGatesDePermisos(): void
    {
        Gate::before(function (User $user, string $ability) {
            return $user->tienePermiso($ability) ?: null;
        });
    }
}
