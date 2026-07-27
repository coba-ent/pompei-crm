<?php

namespace Tests\Unit;

use App\Models\CondicionIva;
use App\Models\Empresa;
use App\Services\FuncionesAvanzadas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FuncionesAvanzadasBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(array $flags): Empresa
    {
        $condicion = CondicionIva::create(['nombre' => 'Responsable Inscripto', 'codigo_afip' => '1', 'requiere_cuit' => true]);

        return Empresa::create(array_merge([
            'razon_social' => 'Emisor de Prueba',
            'cuit' => '20111111112',
            'condicion_iva_id' => $condicion->id,
            'ambiente_arca' => 'testing',
        ], $flags));
    }

    public function test_refleja_los_flags_de_empresa_en_config_negocio(): void
    {
        $this->empresa([
            'ventas_sin_stock_habilitado' => true,
            'abonos_habilitados' => true,
            'facturacion_electronica_habilitada' => true,
        ]);

        app(FuncionesAvanzadas::class)->reflejarEnConfig();

        $this->assertTrue(config('negocio.ventas_sin_stock'));
        $this->assertTrue(config('negocio.abonos_activo'));
        $this->assertTrue(config('negocio.facturacion_electronica_activo'));
    }

    public function test_refleja_los_flags_desactivados(): void
    {
        $this->empresa([
            'ventas_sin_stock_habilitado' => false,
            'abonos_habilitados' => false,
            'facturacion_electronica_habilitada' => false,
        ]);

        app(FuncionesAvanzadas::class)->reflejarEnConfig();

        $this->assertFalse(config('negocio.ventas_sin_stock'));
        $this->assertFalse(config('negocio.abonos_activo'));
        $this->assertFalse(config('negocio.facturacion_electronica_activo'));
    }

    public function test_sin_fila_empresa_conserva_los_defaults_de_config(): void
    {
        $default = config('negocio.abonos_activo');

        app(FuncionesAvanzadas::class)->reflejarEnConfig();

        $this->assertSame($default, config('negocio.abonos_activo'));
    }
}
