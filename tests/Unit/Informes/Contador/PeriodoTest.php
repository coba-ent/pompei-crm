<?php

namespace Tests\Unit\Informes\Contador;

use App\Services\Informes\Contador\Periodo;
use PHPUnit\Framework\TestCase;

class PeriodoTest extends TestCase
{
    public function test_periodo_mensual_nombres_de_archivo(): void
    {
        $p = new Periodo(2026, 3);

        $this->assertTrue($p->esMensual());
        $this->assertSame('IVA Ventas Marzo - 2026.xlsx', $p->nombreIvaVentas());
        $this->assertSame('IVA Compras Marzo - 2026.xlsx', $p->nombreIvaCompras());
        $this->assertSame('IVA Digital Marzo - 2026.zip', $p->nombreIvaDigital());
        $this->assertSame('PDFs Facturas de Venta Marzo - 2026.zip', $p->nombrePdfsFacturas());
        $this->assertSame('del mes de Marzo de 2026', $p->textoPeriodo());
    }

    public function test_periodo_anual_nombres_de_archivo(): void
    {
        $p = new Periodo(2026);

        $this->assertFalse($p->esMensual());
        $this->assertSame('IVA Ventas - 2026.xlsx', $p->nombreIvaVentas());
        $this->assertSame('IVA Compras - 2026.xlsx', $p->nombreIvaCompras());
        $this->assertSame('del año 2026', $p->textoPeriodo());
    }
}
