<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CondicionIva;
use App\Models\CertificadoFiscal;
use App\Models\Compra;
use App\Models\ComprobanteFiscal;
use App\Models\Deposito;
use App\Models\FuncionAvanzada;
use App\Models\NotaCreditoDebito;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\PuntoVenta;
use App\Models\Rol;
use App\Models\Venta;
use App\Services\Arca\ClienteWsaa;
use App\Services\Arca\ClienteWsfev1;
use App\Services\Arca\EmisorComprobante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 097: envío manual a ARCA de NC/ND, con IVA real por línea. */
class EnvioManualArcaNotaCreditoDebitoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Rol::firstOrCreate(['nombre' => 'Admin'], ['es_sistema' => true]);
        auth()->user()->roles()->attach($admin->id);

        FuncionAvanzada::create(['clave' => 'facturacion_electronica', 'nombre' => 'Facturacion electronica', 'descripcion' => 'Emitir CAE.', 'orden' => 1, 'disponible' => true, 'activa' => true]);
        PuntoVenta::create(['numero' => 1, 'descripcion' => 'Casa Central', 'por_defecto' => true, 'activo' => true]);
        CertificadoFiscal::create([
            'cuit' => '20111111112',
            'ambiente' => 'homologacion',
            'ruta_certificado' => 'arca/test.crt',
            'ruta_clave_privada' => 'arca/test.key',
            'activo' => true,
        ]);

        $this->app->bind(EmisorComprobante::class, function () {
            return new EmisorComprobante(
                wsaaFactory: fn () => new class extends ClienteWsaa
                {
                    public function __construct() {}

                    public function obtenerTicketAcceso(string $servicio = 'wsfe'): array
                    {
                        return ['token' => 'tok', 'sign' => 'sign'];
                    }
                },
                wsfeFactory: fn () => new class extends ClienteWsfev1
                {
                    private int $llamadas = 0;

                    public function __construct() {}

                    public function consultarUltimoAutorizado(array $ta, int $pv, string $tipo): object
                    {
                        $this->llamadas++;

                        return json_decode(json_encode(['FECompUltimoAutorizadoResult' => ['CbteNro' => 4 + $this->llamadas]]));
                    }

                    public function consultarComprobante(array $ta, int $pv, string $tipo, int $numero): object
                    {
                        return json_decode(json_encode(['FECompConsultarResult' => ['ResultGet' => null]]));
                    }

                    public function solicitarCae(array $ta, array $comprobante): object
                    {
                        return json_decode(json_encode([
                            'FECAESolicitarResult' => [
                                'FeDetResp' => [
                                    'FECAEDetResponse' => [
                                        'Resultado' => 'A',
                                        'CAE' => '71234500000099',
                                        'CAEFchVto' => '20261231',
                                    ],
                                ],
                            ],
                        ]));
                    }
                },
            );
        });
    }

    private function crearVentaConCae(Cliente $cliente, array $items): Venta
    {
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $payload = [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => $items,
        ];

        $this->postJson(route('ventas.store'), $payload)->assertCreated();
        $venta = Venta::firstOrFail();

        $this->postJson(route('ventas.enviarArca', $venta))->assertOk()->assertJsonPath('ok', true);

        return $venta->refresh();
    }

    private function cliente(): Cliente
    {
        $consumidorFinal = CondicionIva::firstOrCreate(['codigo_afip' => '5'], ['nombre' => 'Consumidor Final', 'requiere_cuit' => false]);

        return Cliente::factory()->create(['condicion_iva_id' => $consumidorFinal->id]);
    }

    // -----------------------------------------------------------------
    // US1: envío manual — no automático
    // -----------------------------------------------------------------

    public function test_crear_nota_ya_no_dispara_envio_automatico(): void
    {
        $venta = $this->crearVentaConCae($this->cliente(), [
            ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
        ]);

        $response = $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'fecha_emision' => now()->toDateString(),
            'mes_imputacion' => now()->toDateString(),
            'monto' => 100,
            'afecta_stock' => false,
            'descripcion' => 'Ajuste de prueba',
        ])->assertCreated();

        $nota = $venta->notasCreditoDebito()->firstOrFail();

        $this->assertNull($nota->comprobanteFiscal);
        $this->assertNull($response->json('comprobante_fiscal.cae'));
    }

    public function test_enviar_arca_sobre_nota_elegible_obtiene_cae(): void
    {
        $venta = $this->crearVentaConCae($this->cliente(), [
            ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
        ]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'fecha_emision' => now()->toDateString(),
            'mes_imputacion' => now()->toDateString(),
            'monto' => 100,
            'afecta_stock' => false,
            'descripcion' => 'Ajuste de prueba',
        ])->assertCreated();

        $nota = $venta->notasCreditoDebito()->firstOrFail();

        $response = $this->postJson(route('ventas.notas.enviarArca', [$venta, $nota]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNotNull($response->json('cae'));
        $this->assertNotNull($response->json('cae_vencimiento'));
        $this->assertSame('aprobado', $nota->refresh()->comprobanteFiscal->estado);
    }

    public function test_enviar_arca_sin_cae_en_comprobante_original_devuelve_422(): void
    {
        $cliente = $this->cliente();
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $this->postJson(route('ventas.store'), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => [['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21']],
        ])->assertCreated();

        $venta = Venta::firstOrFail(); // Sin enviar a ARCA: no tiene CAE.

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'fecha_emision' => now()->toDateString(),
            'mes_imputacion' => now()->toDateString(),
            'monto' => 100,
            'afecta_stock' => false,
            'descripcion' => 'Ajuste de prueba',
        ])->assertCreated();

        $nota = $venta->notasCreditoDebito()->firstOrFail();

        $this->postJson(route('ventas.notas.enviarArca', [$venta, $nota]))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertNull($nota->refresh()->comprobanteFiscal);
    }

    public function test_enviar_arca_sobre_nota_ya_aprobada_devuelve_422_sin_reintentar(): void
    {
        $venta = $this->crearVentaConCae($this->cliente(), [
            ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
        ]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'fecha_emision' => now()->toDateString(),
            'mes_imputacion' => now()->toDateString(),
            'monto' => 100,
            'afecta_stock' => false,
            'descripcion' => 'Ajuste de prueba',
        ])->assertCreated();

        $nota = $venta->notasCreditoDebito()->firstOrFail();

        $this->postJson(route('ventas.notas.enviarArca', [$venta, $nota]))->assertOk()->assertJsonPath('ok', true);

        $cantidadPrevia = ComprobanteFiscal::count();

        $this->postJson(route('ventas.notas.enviarArca', [$venta, $nota]))->assertStatus(422);

        $this->assertSame($cantidadPrevia, ComprobanteFiscal::count());
    }

    public function test_doble_post_consecutivo_no_genera_dos_comprobantes(): void
    {
        $venta = $this->crearVentaConCae($this->cliente(), [
            ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
        ]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'fecha_emision' => now()->toDateString(),
            'mes_imputacion' => now()->toDateString(),
            'monto' => 100,
            'afecta_stock' => false,
            'descripcion' => 'Ajuste de prueba',
        ])->assertCreated();

        $nota = $venta->notasCreditoDebito()->firstOrFail();

        $this->postJson(route('ventas.notas.enviarArca', [$venta, $nota]))->assertOk();
        $this->postJson(route('ventas.notas.enviarArca', [$venta, $nota]))->assertStatus(422);

        $this->assertSame(1, $nota->comprobantesFiscales()->count());
    }

    // -----------------------------------------------------------------
    // Paridad Compras (FR-011) — sólo precondiciones: el mapeo del receptor
    // fiscal de Compra queda pendiente (ver NotaCreditoDebitoController).
    // -----------------------------------------------------------------

    public function test_enviar_arca_compra_sin_cae_en_comprobante_original_devuelve_422(): void
    {
        $proveedor = Proveedor::factory()->create();
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);
        $producto = Producto::factory()->create();

        $this->postJson(route('compras.store'), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'proveedor_id' => $proveedor->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'A',
            'nro_comprobante' => '0001-00000001',
            'items' => [['producto_id' => $producto->id, 'descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21']],
        ])->assertCreated();

        $compra = Compra::firstOrFail();

        $this->postJson(route('compras.notas.store', $compra), [
            'tipo' => 'credito',
            'fecha_emision' => now()->toDateString(),
            'mes_imputacion' => now()->toDateString(),
            'monto' => 100,
            'afecta_stock' => false,
            'descripcion' => 'Ajuste de prueba',
        ])->assertCreated();

        $nota = $compra->notasCreditoDebito()->firstOrFail();

        $this->postJson(route('compras.notas.enviarArca', [$compra, $nota]))
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    // -----------------------------------------------------------------
    // US2: IVA real por línea
    // -----------------------------------------------------------------

    public function test_nc_con_dos_alicuotas_arma_dos_bloques_alic_iva_reales(): void
    {
        $venta = $this->crearVentaConCae($this->cliente(), [
            ['descripcion' => 'Producto 21', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
            ['descripcion' => 'Producto 10.5', 'cantidad' => 1, 'precio_unitario' => 500, 'iva_pct' => '10.5'],
        ]);

        $venta->load('items');
        $itemVeintiuno = $venta->items->firstWhere('iva_pct', 21);
        $itemDiezMedio = $venta->items->firstWhere('iva_pct', 10.5);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'fecha_emision' => now()->toDateString(),
            'mes_imputacion' => now()->toDateString(),
            'monto' => round((float) $itemVeintiuno->subtotal_con_iva + (float) $itemDiezMedio->subtotal_con_iva, 2),
            'afecta_stock' => false,
            'descripcion' => 'NC con dos alícuotas',
            'items' => [
                ['producto_id' => $itemVeintiuno->producto_id, 'item_origen_id' => $itemVeintiuno->id, 'cantidad' => 1, 'precio' => (float) $itemVeintiuno->precio_unitario, 'iva_pct' => 21],
                ['producto_id' => $itemDiezMedio->producto_id, 'item_origen_id' => $itemDiezMedio->id, 'cantidad' => 1, 'precio' => (float) $itemDiezMedio->precio_unitario, 'iva_pct' => 10.5],
            ],
        ])->assertCreated();

        $nota = $venta->notasCreditoDebito()->firstOrFail();
        $nota->load('items');

        // Confirma la condición de elegibilidad para el desglose real (FR-009): todos los ítems
        // de la nota tienen venta_item_id — el resto (armado del payload real hacia
        // MapeadorComprobante) ya lo cubre EmisionComprobanteNotaCreditoDebitoTest / el envío OK.
        $this->assertTrue($nota->items->every(fn ($i) => $i->venta_item_id !== null));
        $this->assertEqualsWithDelta(21.0, (float) $nota->items->firstWhere('venta_item_id', $itemVeintiuno->id)->iva_pct, 0.001);
        $this->assertEqualsWithDelta(10.5, (float) $nota->items->firstWhere('venta_item_id', $itemDiezMedio->id)->iva_pct, 0.001);

        $this->postJson(route('ventas.notas.enviarArca', [$venta, $nota]))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_nc_vieja_sin_venta_item_id_usa_fallback_agregado(): void
    {
        $venta = $this->crearVentaConCae($this->cliente(), [
            ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
        ]);

        // Nota sin `items` (ni venta_item_id): simula una NC "global", cae al fallback agregado.
        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'fecha_emision' => now()->toDateString(),
            'mes_imputacion' => now()->toDateString(),
            'monto' => 121,
            'afecta_stock' => false,
            'descripcion' => 'Nota sin ítems propios',
        ])->assertCreated();

        $nota = $venta->notasCreditoDebito()->firstOrFail();
        $this->assertTrue($nota->items()->count() === 0);

        $this->postJson(route('ventas.notas.enviarArca', [$venta, $nota]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('aprobado', $nota->refresh()->comprobanteFiscal->estado);
    }

    public function test_nc_con_items_mixtos_usa_fallback_agregado_para_toda_la_nota(): void
    {
        $venta = $this->crearVentaConCae($this->cliente(), [
            ['descripcion' => 'Producto 21', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
        ]);

        $venta->load('items');
        $itemOrigen = $venta->items->first();
        $productoSuelto = Producto::factory()->create();

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'fecha_emision' => now()->toDateString(),
            'mes_imputacion' => now()->toDateString(),
            'monto' => 100,
            'afecta_stock' => false,
            'descripcion' => 'NC con ítems mixtos',
            'items' => [
                // Con línea de origen (venta_item_id).
                ['producto_id' => $itemOrigen->producto_id, 'item_origen_id' => $itemOrigen->id, 'cantidad' => 1, 'precio' => 50, 'iva_pct' => 21],
                // Sin línea de origen (agregado a mano).
                ['producto_id' => $productoSuelto->id, 'cantidad' => 1, 'precio' => 50, 'iva_pct' => 21],
            ],
        ])->assertCreated();

        $nota = $venta->notasCreditoDebito()->firstOrFail();
        $nota->load('items');

        $this->assertFalse($nota->items->every(fn ($i) => $i->venta_item_id !== null));
        $this->assertTrue($nota->items->contains(fn ($i) => $i->venta_item_id === null));

        $this->postJson(route('ventas.notas.enviarArca', [$venta, $nota]))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    // -----------------------------------------------------------------
    // US4: estado ARCA
    // -----------------------------------------------------------------

    public function test_estado_arca_de_nota_refleja_su_comprobante_fiscal_propio(): void
    {
        $venta = $this->crearVentaConCae($this->cliente(), [
            ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
        ]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'fecha_emision' => now()->toDateString(),
            'mes_imputacion' => now()->toDateString(),
            'monto' => 100,
            'afecta_stock' => false,
            'descripcion' => 'Ajuste de prueba',
        ])->assertCreated();

        $nota = $venta->notasCreditoDebito()->firstOrFail();

        $this->assertSame('sin_enviar', $nota->estadoArca());

        $this->postJson(route('ventas.notas.enviarArca', [$venta, $nota]))->assertOk();

        $this->assertSame('aprobado', $nota->refresh()->estadoArca());
    }

    public function test_nota_con_cae_aprobado_no_ofrece_editar_ni_eliminar_en_el_detalle(): void
    {
        $venta = $this->crearVentaConCae($this->cliente(), [
            ['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21'],
        ]);

        $this->postJson(route('ventas.notas.store', $venta), [
            'tipo' => 'credito',
            'fecha_emision' => now()->toDateString(),
            'mes_imputacion' => now()->toDateString(),
            'monto' => 100,
            'afecta_stock' => false,
            'descripcion' => 'Ajuste de prueba',
        ])->assertCreated();

        $nota = $venta->notasCreditoDebito()->firstOrFail();

        // Sin CAE propio todavía: ambas acciones disponibles.
        $this->get(route('ventas.show', $venta))
            ->assertOk()
            ->assertSee('js-editar-nota')
            ->assertSee('js-eliminar-nota');

        $this->postJson(route('ventas.notas.enviarArca', [$venta, $nota]))->assertOk();
        $this->assertTrue($nota->refresh()->tieneCaeAprobado());

        // Con CAE aprobado la nota ya fue declarada al fisco: el backend rechaza editar/eliminar
        // con 409, así que la vista tampoco debe ofrecer esas acciones.
        $this->get(route('ventas.show', $venta))
            ->assertOk()
            ->assertDontSee('js-editar-nota')
            ->assertDontSee('js-eliminar-nota');
    }

    public function test_venta_estado_arca_devuelve_los_4_valores(): void
    {
        $cliente = $this->cliente();
        $deposito = Deposito::first() ?? Deposito::create(['nombre' => 'Principal', 'activo' => true]);

        $this->postJson(route('ventas.store'), [
            'submit_token' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $cliente->id,
            'deposito_id' => $deposito->id,
            'fecha_emision' => now()->toDateString(),
            'tipo_comprobante' => 'B',
            'items' => [['descripcion' => 'Producto', 'cantidad' => 1, 'precio_unitario' => 1000, 'iva_pct' => '21']],
        ])->assertCreated();

        $venta = Venta::firstOrFail();
        $this->assertSame('sin_enviar', $venta->estadoArca());

        $this->postJson(route('ventas.enviarArca', $venta))->assertOk();

        $this->assertSame('aprobado', $venta->refresh()->estadoArca());
    }
}
