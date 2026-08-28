<?php

namespace Tests\Feature\Informes\Contador;

use App\Services\Informes\Contador\OpcionesEnvio;
use App\Services\Informes\Contador\PaqueteContador;
use App\Services\Informes\Contador\Periodo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T008 (spec 087) — `PaqueteContador::listar()` contra los cuatro estados de las capturas
 * (research §, spec Comportamiento del panel): traducción directa de la tabla del relevamiento.
 */
class PaqueteContadorListarTest extends TestCase
{
    use RefreshDatabase;

    private function opciones(bool $pdfs = false): OpcionesEnvio
    {
        return new OpcionesEnvio(incluyeElectronicas: true, incluyeManuales: false, incluyePdfs: $pdfs);
    }

    public function test_sin_periodo_el_panel_esta_vacio(): void
    {
        $lista = app(PaqueteContador::class)->listar(null, $this->opciones());

        $this->assertSame([], $lista);
    }

    public function test_con_anio_sin_mes_lista_los_dos_xlsx_anuales_sin_iva_digital(): void
    {
        $lista = app(PaqueteContador::class)->listar(new Periodo(2026), $this->opciones());

        $this->assertSame(['IVA Ventas - 2026.xlsx', 'IVA Compras - 2026.xlsx'], $lista);
    }

    public function test_con_anio_y_mes_lista_los_dos_xlsx_mensuales_mas_iva_digital(): void
    {
        $lista = app(PaqueteContador::class)->listar(new Periodo(2026, 3), $this->opciones());

        $this->assertSame(
            ['IVA Ventas Marzo - 2026.xlsx', 'IVA Compras Marzo - 2026.xlsx', 'IVA Digital Marzo - 2026.zip'],
            $lista
        );
    }

    public function test_con_pdf_tildado_suma_el_zip_de_pdfs(): void
    {
        $lista = app(PaqueteContador::class)->listar(new Periodo(2026, 3), $this->opciones(pdfs: true));

        $this->assertSame(
            ['IVA Ventas Marzo - 2026.xlsx', 'IVA Compras Marzo - 2026.xlsx', 'IVA Digital Marzo - 2026.zip', 'PDFs Facturas de Venta Marzo - 2026.zip'],
            $lista
        );
    }

    /** FR-012b: el ZIP de PDFs no corresponde en modo anual, aunque la casilla esté tildada. */
    public function test_con_anio_solo_el_pdf_tildado_no_agrega_nada(): void
    {
        $lista = app(PaqueteContador::class)->listar(new Periodo(2026), $this->opciones(pdfs: true));

        $this->assertSame(['IVA Ventas - 2026.xlsx', 'IVA Compras - 2026.xlsx'], $lista);
    }
}
