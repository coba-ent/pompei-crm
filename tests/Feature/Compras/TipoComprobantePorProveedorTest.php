<?php

namespace Tests\Feature\Compras;

use App\Models\CondicionIva;
use App\Models\Proveedor;
use App\Models\Rol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El formulario de Compra precarga el Tipo de Comprobante según el proveedor elegido.
 *
 * Antes quedaba siempre en el default de Configuración (B), así que un Responsable Inscripto
 * como FV — que en el histórico nos facturó 478 veces en A — aparecía en B.
 *
 * La derivación sale del histórico real: RI → A (1.356 compras contra 66 en B) y
 * Monotributista → C (37 de 37). `tipo_comprobante_defecto` de la ficha manda por encima,
 * pero está NULL en los 148 proveedores migrados desde Contagram, que es por lo que hace
 * falta derivarlo de la condición de IVA.
 */
class TipoComprobantePorProveedorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        auth()->user()->roles()->syncWithoutDetaching(
            Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true])->id
        );
    }

    private function condicion(string $nombre): CondicionIva
    {
        return CondicionIva::firstOrCreate(['nombre' => $nombre]);
    }

    public function test_responsable_inscripto_sugiere_a(): void
    {
        $proveedor = Proveedor::factory()->create([
            'condicion_iva_id' => $this->condicion('Responsable Inscripto')->id,
            'tipo_comprobante_defecto' => null,
        ]);

        $this->assertSame('A', $proveedor->tipoComprobanteSugerido());
    }

    public function test_monotributista_sugiere_c(): void
    {
        $proveedor = Proveedor::factory()->create([
            'condicion_iva_id' => $this->condicion('Monotributista')->id,
            'tipo_comprobante_defecto' => null,
        ]);

        $this->assertSame('C', $proveedor->tipoComprobanteSugerido());
    }

    public function test_sin_condicion_no_sugiere_nada(): void
    {
        $proveedor = Proveedor::factory()->create([
            'condicion_iva_id' => null,
            'tipo_comprobante_defecto' => null,
        ]);

        $this->assertNull($proveedor->tipoComprobanteSugerido());
    }

    public function test_el_defecto_de_la_ficha_manda_sobre_la_condicion(): void
    {
        $proveedor = Proveedor::factory()->create([
            'condicion_iva_id' => $this->condicion('Responsable Inscripto')->id,
            'tipo_comprobante_defecto' => 'B',
        ]);

        $this->assertSame('B', $proveedor->tipoComprobanteSugerido());
    }

    public function test_el_endpoint_de_opciones_expone_el_tipo_para_el_formulario(): void
    {
        $proveedor = Proveedor::factory()->create([
            'nombre' => 'FV PRUEBA',
            'condicion_iva_id' => $this->condicion('Responsable Inscripto')->id,
            'tipo_comprobante_defecto' => null,
            'activo' => true,
        ]);

        $resp = $this->getJson(route('proveedores.opciones', ['q' => 'FV PRUEBA']));

        $resp->assertOk();
        $fila = collect($resp->json('data'))->firstWhere('id', $proveedor->id);

        $this->assertNotNull($fila, 'El proveedor no vino en las opciones.');
        $this->assertSame('A', $fila['tipo_comprobante_defecto']);
    }
}
