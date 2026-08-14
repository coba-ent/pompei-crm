<?php

namespace Tests\Feature\Integraciones;

use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\Integraciones\MercadoLibreConfiguracion;
use App\Models\Integraciones\MercadoLibrePublicacionProducto;
use App\Models\Producto;
use App\Models\Rol;
use App\Services\Stock\StockService;
use Database\Seeders\FuncionAvanzadaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * spec 065 · US3 — configuración del depósito para publicaciones Full.
 *
 * La validación `different` de acá es la más importante de la feature: si el depósito
 * Full y el general coincidieran, el reflejo ML → CRM sobrescribiría el stock físico
 * real del negocio y se abriría el ciclo de sincronización que FR-013 prohíbe.
 */
class MercadoLibreDepositoFullConfiguracionTest extends TestCase
{
    use RefreshDatabase;

    private Deposito $general;

    private Deposito $full;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        (new FuncionAvanzadaSeeder())->run();
        FuncionAvanzada::where('clave', 'mercadolibre')->update(['activa' => true]);

        $this->general = Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $this->full = Deposito::create(['nombre' => 'Mercado Libre Full', 'activo' => true]);

        MercadoLibreConfiguracion::actual()->update(['deposito_id' => $this->general->id]);
    }

    private function guardar(array $datos)
    {
        return $this->patchJson(route('configuracion.mercadolibre.ventas.configurar'), array_merge([
            'creacion_automatica' => 0,
            'frecuencia_sync_minutos' => 15,
            'dias_primera_sync' => 30,
            'deposito_id' => $this->general->id,
        ], $datos));
    }

    /** T017 · FR-017: el mismo depósito para ambos usos se rechaza con 422 y explica el motivo. */
    public function test_el_mismo_deposito_para_full_y_general_devuelve_422(): void
    {
        $respuesta = $this->guardar(['deposito_full_id' => $this->general->id]);

        $respuesta->assertStatus(422)->assertJsonValidationErrors('deposito_full_id');
        $this->assertStringContainsString(
            'sobrescribiría el stock real de tu depósito',
            $respuesta->json('errors.deposito_full_id.0')
        );
        $this->assertNull(MercadoLibreConfiguracion::actual()->deposito_full_id);
    }

    /** T017 · FR-015: un depósito distinto se guarda sin problema. */
    public function test_un_deposito_distinto_se_guarda(): void
    {
        $this->guardar(['deposito_full_id' => $this->full->id])->assertOk();

        $configuracion = MercadoLibreConfiguracion::actual();
        $this->assertSame($this->full->id, $configuracion->deposito_full_id);
        $this->assertSame($this->full->id, $configuracion->depositoFullEfectivoONulo()?->id);
    }

    /** T017 · FR-016: es opcional — vacío guarda igual y deja la funcionalidad de Full sin operar. */
    public function test_dejarlo_vacio_guarda_ok(): void
    {
        MercadoLibreConfiguracion::actual()->update(['deposito_full_id' => $this->full->id]);

        $this->guardar(['deposito_full_id' => null])->assertOk();

        $this->assertNull(MercadoLibreConfiguracion::actual()->fresh()->deposito_full_id);
    }

    /**
     * data-model §ml_configuracion: sin fallback a Deposito::porDefecto(). Un depósito
     * inactivo se comporta como "sin configurar", nunca cae al depósito físico general.
     */
    public function test_un_deposito_full_inactivo_se_comporta_como_sin_configurar(): void
    {
        MercadoLibreConfiguracion::actual()->update(['deposito_full_id' => $this->full->id]);
        $this->full->update(['activo' => false]);

        $this->assertNull(MercadoLibreConfiguracion::actual()->depositoFullEfectivoONulo());
    }

    /** T021 · FR-026: con publicaciones Full y sin depósito configurado, la pantalla avisa. */
    public function test_la_pantalla_avisa_si_hay_full_sin_deposito_configurado(): void
    {
        $producto = Producto::factory()->create();
        MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA1', 'producto_id' => $producto->id, 'logistic_type' => 'fulfillment',
        ]);

        $this->get(route('configuracion.mercadolibre.index'))
            ->assertOk()
            ->assertSee('no configuraste un depósito para Full', false);
    }

    /** T021 · FR-026: configurado el depósito, el aviso desaparece. */
    public function test_configurado_el_deposito_el_aviso_desaparece(): void
    {
        $producto = Producto::factory()->create();
        MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA1', 'producto_id' => $producto->id, 'logistic_type' => 'fulfillment',
        ]);
        MercadoLibreConfiguracion::actual()->update(['deposito_full_id' => $this->full->id]);

        $this->get(route('configuracion.mercadolibre.index'))
            ->assertOk()
            ->assertDontSee('no configuraste un depósito para Full', false);
    }

    /**
     * T021a · FR-013 (research R7): un ajuste de stock en el depósito Full NO marca ningún
     * vínculo como pendiente, así que no se forma el ciclo CRM → ML → CRM. La propiedad se
     * apoya enteramente en la validación `different` de arriba: `MovimientoStockObserver`
     * sólo marca pendientes los movimientos del depósito **general** de Mercado Libre, y
     * eso sólo alcanza mientras los dos depósitos no puedan ser el mismo.
     */
    public function test_un_ajuste_en_el_deposito_full_no_marca_ningun_vinculo_pendiente(): void
    {
        MercadoLibreConfiguracion::actual()->update(['deposito_full_id' => $this->full->id]);

        $producto = Producto::factory()->create(['tipo' => 'producto']);
        $vinculoFull = MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA1', 'producto_id' => $producto->id, 'logistic_type' => 'fulfillment',
        ]);
        $vinculoPropio = MercadoLibrePublicacionProducto::create([
            'ml_item_id' => 'MLA2', 'producto_id' => $producto->id, 'logistic_type' => 'xd_drop_off',
        ]);

        app(StockService::class)->ajustar($producto, null, $this->full, 4, 'reflejo Full');

        $this->assertFalse($vinculoFull->fresh()->stock_pendiente);
        $this->assertFalse($vinculoPropio->fresh()->stock_pendiente);

        // Contraprueba: el mismo ajuste en el depósito general SÍ marca pendiente. Si esto
        // fallara, el test de arriba estaría dando un falso verde por otro motivo.
        app(StockService::class)->ajustar($producto, null, $this->general, 3, 'carga normal');
        $this->assertTrue($vinculoPropio->fresh()->stock_pendiente);
    }

    /**
     * Regresión de un bug reportado en producción: el depósito Full se guardaba bien pero el
     * formulario lo perdía y parecía que no se había guardado.
     *
     * El endpoint de estado no devolvía `deposito_full_id`, y el front repuebla el selector con
     * `conf.deposito_full_id || ''` — un campo ausente lo vacía. Guardar bien no alcanza si lo
     * guardado no se puede volver a leer: los tests de arriba pasaban igual porque ninguno miraba
     * el camino de lectura.
     */
    public function test_el_estado_devuelve_el_deposito_full_configurado(): void
    {
        $this->guardar(['deposito_full_id' => $this->full->id])->assertOk();

        $this->getJson(route('configuracion.mercadolibre.estado'))
            ->assertOk()
            ->assertJsonPath('configuracion.deposito_full_id', $this->full->id);
    }
}
