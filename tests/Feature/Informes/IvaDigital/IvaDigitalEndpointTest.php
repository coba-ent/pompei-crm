<?php

namespace Tests\Feature\Informes\IvaDigital;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Informes\ConPermisoInformes;
use Tests\TestCase;

/**
 * T019 (spec 086) — endpoint HTTP: descarga correcta con mes+año (FR-001), rechazo con 422 cuando
 * falta el mes (FR-004), y respeta los permisos del módulo de Informes (mismo gate que ventas/compras).
 */
class IvaDigitalEndpointTest extends TestCase
{
    use ConPermisoInformes, RefreshDatabase;

    public function test_descarga_correctamente_con_mes_y_anio(): void
    {
        $this->autenticarConPermisoInformes();

        $response = $this->get('/informes/contador/iva-digital?mes=8&anio=2026');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
        $this->assertStringContainsString(
            'IVA Digital Ventas y Compras Agosto 2026.zip',
            $response->headers->get('content-disposition')
        );
    }

    public function test_rechaza_con_422_cuando_falta_el_mes(): void
    {
        $this->autenticarConPermisoInformes();

        $response = $this->get('/informes/contador/iva-digital?anio=2026');

        $response->assertStatus(422);
    }

    public function test_sin_permiso_de_informes_devuelve_403(): void
    {
        $response = $this->get('/informes/contador/iva-digital?mes=8&anio=2026');

        $response->assertForbidden();
    }
}
