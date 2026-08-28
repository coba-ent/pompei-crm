<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Una compra "Sin Factura" (`tipo_comprobante = 'S'`) no lleva N° de Comprobante.
 *
 * El formulario ofrece esa opción desde siempre y la base declara `nro_comprobante` nullable, pero
 * la validación lo pedía `required` igual: no se podía cargar por pantalla una compra sin factura,
 * aunque 952 de las 2.404 compras migradas de Contagram están justamente en esa condición.
 *
 * El caso de los DOS registros sin número es el que importa: `compras` tiene
 * `unique(['tipo_comprobante','nro_comprobante'])`, y en MySQL dos NULL no colisionan pero dos
 * cadenas vacías sí — de ahí que el request normalice `''` a NULL antes de validar.
 */
class CompraSinFacturaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        auth()->user()->roles()->syncWithoutDetaching(Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'submit_token' => (string) Str::uuid(),
            'proveedor_id' => Proveedor::factory()->create()->id,
            'deposito_id' => Deposito::create(['nombre' => 'Depósito', 'activo' => true])->id,
            'fecha_emision' => '2026-08-18',
            'items' => [[
                'producto_id' => Producto::factory()->create(['tipo' => 'servicio'])->id,
                'descripcion' => 'Servicio',
                'cantidad' => 1,
                'precio_unitario' => 100,
                'iva_pct' => '21',
            ]],
        ], $extra);
    }

    public function test_sin_factura_se_puede_crear_sin_numero_de_comprobante(): void
    {
        $payload = $this->payload(['tipo_comprobante' => 'S', 'nro_comprobante' => '']);

        $this->postJson(route('compras.store'), $payload)
            ->assertCreated()
            ->assertJson(['ok' => true]);

        $compra = Compra::latest('id')->first();
        $this->assertSame('S', $compra->tipo_comprobante);
        $this->assertNull($compra->nro_comprobante, 'Vacío tiene que entrar como NULL, no como cadena vacía.');
    }

    /** El índice único `(tipo_comprobante, nro_comprobante)` tolera varios NULL, pero no varios ''. */
    public function test_dos_compras_sin_factura_conviven_sin_chocar_el_indice_unico(): void
    {
        foreach ([1, 2] as $_) {
            $this->postJson(route('compras.store'), $this->payload(['tipo_comprobante' => 'S', 'nro_comprobante' => '']))
                ->assertCreated();
        }

        $this->assertSame(2, Compra::where('tipo_comprobante', 'S')->count());
    }

    /** Con tipo fiscal real (A/B/C) el número sigue siendo obligatorio: no se relajó de más. */
    public function test_con_tipo_fiscal_el_numero_sigue_siendo_obligatorio(): void
    {
        $this->postJson(route('compras.store'), $this->payload(['tipo_comprobante' => 'A', 'nro_comprobante' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('nro_comprobante');
    }

    /** Sin tipo de comprobante tampoco se afloja: sólo 'S' exime del número. */
    public function test_sin_tipo_de_comprobante_el_numero_sigue_siendo_obligatorio(): void
    {
        $this->postJson(route('compras.store'), $this->payload(['nro_comprobante' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('nro_comprobante');
    }

    public function test_editar_una_compra_sin_factura_no_exige_numero(): void
    {
        $this->postJson(route('compras.store'), $this->payload(['tipo_comprobante' => 'S', 'nro_comprobante' => '']))
            ->assertCreated();

        $compra = Compra::latest('id')->first();
        $payload = $this->payload(['tipo_comprobante' => 'S', 'nro_comprobante' => '']);
        unset($payload['submit_token']);

        $this->putJson(route('compras.update', $compra), $payload)->assertOk();

        $this->assertNull($compra->fresh()->nro_comprobante);
    }
}
