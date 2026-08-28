<?php

namespace Tests\Feature\Informes\Contador;

use App\Models\Cliente;
use App\Models\Venta;
use App\Models\VentaItem;
use App\Services\Informes\Contador\OpcionesEnvio;
use App\Services\Informes\Contador\PaqueteContador;
use App\Services\Informes\Contador\Periodo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T011 (spec 087, SC-004) — coherencia `listar()` / `generar()`: para cada combinación de período y
 * casillas, los nombres previstos son exactamente los de los archivos generados. Es el test que
 * sostiene toda la arquitectura: sin él, el panel puede anunciar una cosa y el correo llevar otra.
 */
class PaqueteContadorGenerarTest extends TestCase
{
    use RefreshDatabase;

    private function ventaConIva(): void
    {
        $cliente = Cliente::factory()->create(['cuit' => '20111111112', 'tipo_documento' => 'CUIT']);
        $venta = Venta::factory()->create([
            'cliente_id' => $cliente->id, 'tipo_comprobante' => 'B',
            'nro_comprobante' => '0001-00000001', 'fecha_emision' => '2026-08-10', 'total' => 1210,
        ]);
        VentaItem::create([
            'venta_id' => $venta->id, 'descripcion' => 'Ítem', 'cantidad' => 1,
            'precio_unitario' => 1000, 'iva_pct' => '21', 'subtotal' => 1000, 'subtotal_con_iva' => 1210,
        ]);
    }

    /** @return array<int, array{0: Periodo, 1: OpcionesEnvio}> */
    public static function combinaciones(): array
    {
        return [
            'anio solo' => [new Periodo(2026), new OpcionesEnvio(true, false, false)],
            'anio y mes' => [new Periodo(2026, 8), new OpcionesEnvio(true, false, false)],
            'anio y mes con pdfs' => [new Periodo(2026, 8), new OpcionesEnvio(true, false, true)],
            'solo manuales' => [new Periodo(2026, 8), new OpcionesEnvio(false, true, false)],
        ];
    }

    /**
     * @dataProvider combinaciones
     */
    public function test_listar_y_generar_producen_los_mismos_nombres(Periodo $periodo, OpcionesEnvio $opciones): void
    {
        $this->ventaConIva();

        $paquete = app(PaqueteContador::class);

        $previstos = $paquete->listar($periodo, $opciones);
        $archivos = $paquete->generar($periodo, $opciones);

        $this->assertSame($previstos, array_keys($archivos));

        foreach ($archivos as $ruta) {
            $this->assertFileExists($ruta);
            @unlink($ruta);
        }
    }
}
