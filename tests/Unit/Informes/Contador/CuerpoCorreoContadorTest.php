<?php

namespace Tests\Unit\Informes\Contador;

use App\Services\Informes\Contador\CuerpoCorreoContador;
use App\Services\Informes\Contador\Periodo;
use PHPUnit\Framework\TestCase;

class CuerpoCorreoContadorTest extends TestCase
{
    public function test_asunto_incluye_el_nombre_del_negocio(): void
    {
        $this->assertSame('Información de Pompei', (new CuerpoCorreoContador)->asunto('Pompei'));
    }

    public function test_cuerpo_mensual_nombra_el_mes_y_lista_los_adjuntos(): void
    {
        $cuerpo = (new CuerpoCorreoContador)->cuerpo(
            new Periodo(2026, 3),
            ['IVA Ventas Marzo - 2026.xlsx', 'IVA Compras Marzo - 2026.xlsx', 'IVA Digital Marzo - 2026.zip']
        );

        $this->assertStringContainsString('del mes de Marzo de 2026', $cuerpo);
        $this->assertStringContainsString('IVA Ventas Marzo - 2026.xlsx', $cuerpo);
        $this->assertStringContainsString('IVA Compras Marzo - 2026.xlsx', $cuerpo);
        $this->assertStringContainsString('IVA Digital Marzo - 2026.zip', $cuerpo);
    }

    /** FR-014: corrige el hueco gramatical del original ("del mes de de 2026"). */
    public function test_cuerpo_anual_se_refiere_al_anio_sin_huecos_gramaticales(): void
    {
        $cuerpo = (new CuerpoCorreoContador)->cuerpo(new Periodo(2026), ['IVA Ventas - 2026.xlsx', 'IVA Compras - 2026.xlsx']);

        $this->assertStringContainsString('del año 2026', $cuerpo);
        $this->assertStringNotContainsString('del mes de de', $cuerpo);
    }
}
