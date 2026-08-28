<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * spec 013: toda la app quedó detrás de 'auth'. Los tests de negocio (anteriores a esta spec)
     * asumen sesión iniciada; se autentica un usuario por defecto salvo que el test la desactive
     * (p. ej. los que ejercitan el propio flujo de login/guest).
     */
    protected bool $autenticado = true;

    /**
     * Vendedor por defecto, como lo tiene una instalación real.
     *
     * `vendedor_id` es obligatorio al crear una venta/presupuesto, y los FormRequest caen al
     * vendedor configurado en Configuración → Ventas cuando el alta no trae uno (la conversión de
     * órdenes de ML/Tiendanube y la de Presupuesto a Venta no lo mandan). En producción esa
     * configuración existe; sin ella acá, cualquier test que dé de alta una venta fallaría por un
     * dato de configuración que no es lo que está probando. Mismo criterio que la autenticación
     * por defecto de arriba.
     */
    protected bool $conConfiguracionVentas = true;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            return;
        }

        if ($this->autenticado) {
            $this->actingAs(User::factory()->create());
        }

        if ($this->conConfiguracionVentas) {
            \App\Models\ConfiguracionVentas::query()->updateOrCreate([], [
                'vendedor_id' => \App\Models\Vendedor::firstOrCreate(['nombre' => 'Vendedor por defecto'])->id,
            ]);
        }
    }
}
