<?php

namespace Tests\Feature;

use App\Models\Deposito;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un único "depósito por defecto" en todo el CRM (28/07/2026).
 *
 * Antes convivían dos criterios distintos: los selects de la interfaz mostraban
 * primero el depósito alfabéticamente menor, mientras que las Ventas y la
 * publicación de stock en Mercado Libre usaban el primero por orden de alta.
 * El desfasaje no daba error: el stock se cargaba en un depósito que la
 * integración no mira y la publicación quedaba mal sin ningún aviso
 * (ver MERCADOLIBRE_NOTAS_TECNICAS.md §10).
 */
class DepositoPorDefectoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);
    }

    public function test_el_por_defecto_es_el_primero_de_alta_no_el_primero_alfabetico(): void
    {
        $principal = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        Deposito::create(['nombre' => 'Aaa Alfabeticamente Primero', 'activo' => true]);

        $this->assertSame($principal->id, Deposito::porDefecto()->id);
    }

    public function test_ignora_los_depositos_inactivos(): void
    {
        Deposito::create(['nombre' => 'Viejo', 'activo' => false]);
        $activo = Deposito::create(['nombre' => 'Nuevo', 'activo' => true]);

        $this->assertSame($activo->id, Deposito::porDefecto()->id);
    }

    public function test_sin_depositos_activos_devuelve_null_en_vez_de_explotar(): void
    {
        Deposito::create(['nombre' => 'Inactivo', 'activo' => false]);

        $this->assertNull(Deposito::porDefecto());
    }

    /** El depósito que publica Mercado Libre cae en el mismo por defecto si no se configuró otro. */
    public function test_mercadolibre_usa_el_mismo_por_defecto_cuando_no_hay_uno_configurado(): void
    {
        $principal = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        Deposito::create(['nombre' => 'Aaa Otro', 'activo' => true]);

        MercadoLibreConfiguracion::actual()->update(['deposito_id' => null]);

        $this->assertSame($principal->id, MercadoLibreConfiguracion::actual()->depositoEfectivo()->id);
        $this->assertSame(Deposito::porDefecto()->id, MercadoLibreConfiguracion::actual()->depositoEfectivo()->id);
    }

    public function test_mercadolibre_respeta_el_deposito_configurado_por_encima_del_por_defecto(): void
    {
        Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $elegido = Deposito::create(['nombre' => 'Depósito Mercado Libre', 'activo' => true]);

        MercadoLibreConfiguracion::actual()->update(['deposito_id' => $elegido->id]);

        $this->assertSame($elegido->id, MercadoLibreConfiguracion::actual()->depositoEfectivo()->id);
    }

    /** Si el configurado se desactiva, no se rompe: vuelve al por defecto. */
    public function test_si_el_deposito_configurado_queda_inactivo_vuelve_al_por_defecto(): void
    {
        $principal = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $elegido = Deposito::create(['nombre' => 'Se Desactiva', 'activo' => true]);

        MercadoLibreConfiguracion::actual()->update(['deposito_id' => $elegido->id]);
        $elegido->update(['activo' => false]);

        $this->assertSame($principal->id, MercadoLibreConfiguracion::actual()->depositoEfectivo()->id);
    }

    /** Sin depósitos cargados, la pantalla de configuración de ML igual tiene que abrir. */
    public function test_la_config_de_mercadolibre_no_rompe_si_no_hay_ningun_deposito(): void
    {
        $this->assertSame(0, Deposito::count());

        $this->get(route('configuracion.mercadolibre.index'))->assertOk();
    }

    /** Los selects de la interfaz tienen que venir con el por defecto preseleccionado. */
    public function test_el_select_de_productos_preselecciona_el_deposito_por_defecto(): void
    {
        $principal = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        Deposito::create(['nombre' => 'Aaa Alfabeticamente Primero', 'activo' => true]);

        $respuesta = $this->get(route('productos.index'));

        $respuesta->assertOk();
        // La opción del por defecto viene marcada y rotulada.
        $respuesta->assertSee('value="'.$principal->id.'" selected', false);
        $respuesta->assertSee('(por defecto)', false);
    }
}
