<?php

namespace Tests\Unit\Services\Arca;

use App\Models\CondicionIva;
use App\Services\Arca\ResultadoConsultaPadron;
use Database\Seeders\CondicionIvaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** T006 (spec 037): mapeo de condición de IVA del padrón al catálogo local (research.md R6). */
class ResultadoConsultaPadronTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new CondicionIvaSeeder())->run();
    }

    private function respuestaCon(string $condicionIva): object
    {
        return json_decode(json_encode([
            'personaReturn' => [
                'persona' => [
                    'razonSocial' => 'ACME SA',
                    'domicilio' => [
                        ['tipoDomicilio' => 'FISCAL', 'direccion' => 'AV CORRIENTES 1234', 'localidad' => 'CABA'],
                    ],
                    'estadoClave' => 'ACTIVO',
                    'datosRegimenGeneral' => [
                        'impuesto' => [
                            ['descripcionImpuesto' => $condicionIva],
                        ],
                    ],
                ],
            ],
        ]));
    }

    private function respuestaConProvincia(string $descripcionProvincia): object
    {
        return json_decode(json_encode([
            'personaReturn' => [
                'persona' => [
                    'razonSocial' => 'ACME SA',
                    'domicilio' => [
                        ['tipoDomicilio' => 'FISCAL', 'direccion' => 'AV CORRIENTES 1234', 'descripcionProvincia' => $descripcionProvincia],
                    ],
                    'estadoClave' => 'ACTIVO',
                ],
            ],
        ]));
    }

    /**
     * Bug reportado: el modal de Cliente no autocompletaba Provincia para CUITs con
     * domicilio fiscal en CABA porque ARCA devuelve "CIUDAD AUTONOMA BUENOS AIRES" (catálogo
     * oficial ws_sr_padron_a13, sin "DE" ni tildes) y el matcheo por texto exacto contra
     * `provincias.nombre` ("Ciudad Autónoma de Buenos Aires") fallaba en silencio.
     */
    public function test_mapea_provincia_caba_al_nombre_completo_del_catalogo(): void
    {
        $resultado = ResultadoConsultaPadron::desdeRespuesta('20304050607', $this->respuestaConProvincia('CIUDAD AUTONOMA BUENOS AIRES'));

        $this->assertSame('Ciudad Autónoma de Buenos Aires', $resultado->provinciaFiscal);
    }

    public function test_mapea_provincia_tierra_del_fuego_al_nombre_completo_del_catalogo(): void
    {
        $resultado = ResultadoConsultaPadron::desdeRespuesta('20304050607', $this->respuestaConProvincia('TIERRA DEL FUEGO'));

        $this->assertSame('Tierra del Fuego, Antártida e Islas del Atlántico Sur', $resultado->provinciaFiscal);
    }

    public function test_provincia_sin_mapeo_conocido_se_devuelve_tal_cual(): void
    {
        $resultado = ResultadoConsultaPadron::desdeRespuesta('20304050607', $this->respuestaConProvincia('CORDOBA'));

        $this->assertSame('CORDOBA', $resultado->provinciaFiscal);
    }

    public function test_mapea_responsable_inscripto(): void
    {
        $resultado = ResultadoConsultaPadron::desdeRespuesta('20304050607', $this->respuestaCon('IVA RESPONSABLE INSCRIPTO'));

        $this->assertTrue($resultado->encontrado);
        $this->assertSame('ACME SA', $resultado->razonSocial);
        $this->assertSame(CondicionIva::where('nombre', 'Responsable Inscripto')->value('id'), $resultado->condicionIvaId);
    }

    public function test_mapea_exento(): void
    {
        $resultado = ResultadoConsultaPadron::desdeRespuesta('20304050607', $this->respuestaCon('IVA SUJETO EXENTO'));

        $this->assertSame(CondicionIva::where('nombre', 'Exento')->value('id'), $resultado->condicionIvaId);
    }

    public function test_valor_desconocido_no_matchea_ninguna_condicion(): void
    {
        $resultado = ResultadoConsultaPadron::desdeRespuesta('20304050607', $this->respuestaCon('ALGO QUE NO EXISTE'));

        $this->assertTrue($resultado->encontrado);
        $this->assertNull($resultado->condicionIvaId);
    }

    public function test_cuit_no_encontrado(): void
    {
        $respuesta = json_decode(json_encode(['personaReturn' => null]));

        $resultado = ResultadoConsultaPadron::desdeRespuesta('20304050607', $respuesta);

        $this->assertFalse($resultado->encontrado);
        $this->assertNull($resultado->condicionIvaId);
    }

    private function resultadoBase(?bool $activo = true): ResultadoConsultaPadron
    {
        return ResultadoConsultaPadron::desdeRespuesta('20304050607', json_decode(json_encode([
            'personaReturn' => [
                'persona' => [
                    'razonSocial' => 'ACME SA',
                    'domicilio' => [
                        ['tipoDomicilio' => 'FISCAL', 'direccion' => 'AV CORRIENTES 1234', 'localidad' => 'CABA'],
                    ],
                    'estadoClave' => $activo === true ? 'ACTIVO' : 'INACTIVO',
                ],
            ],
        ])));
    }

    private function respuestaConstanciaCon(array $personaReturn): object
    {
        return json_decode(json_encode(['personaReturn' => $personaReturn]));
    }

    public function test_con_condicion_iva_responsable_inscripto(): void
    {
        $resultado = ResultadoConsultaPadron::conCondicionIva($this->resultadoBase(), $this->respuestaConstanciaCon([
            'datosGenerales' => ['razonSocial' => 'ACME SA'],
            'datosRegimenGeneral' => [
                'impuesto' => [
                    ['idImpuesto' => 30, 'descripcionImpuesto' => 'IVA', 'estadoImpuesto' => 'AC'],
                ],
            ],
        ]));

        $this->assertSame(CondicionIva::where('nombre', 'Responsable Inscripto')->value('id'), $resultado->condicionIvaId);
        $this->assertSame('ACME SA', $resultado->razonSocial);
    }

    public function test_con_condicion_iva_monotributista(): void
    {
        $resultado = ResultadoConsultaPadron::conCondicionIva($this->resultadoBase(), $this->respuestaConstanciaCon([
            'datosGenerales' => ['razonSocial' => 'ACME SA'],
            'datosMonotributo' => ['categoriaMonotributo' => 'B'],
        ]));

        $this->assertSame(CondicionIva::where('nombre', 'Monotributista')->value('id'), $resultado->condicionIvaId);
    }

    public function test_con_condicion_iva_sin_regimen_ni_monotributo_queda_null(): void
    {
        $resultado = ResultadoConsultaPadron::conCondicionIva($this->resultadoBase(), $this->respuestaConstanciaCon([
            'datosGenerales' => ['razonSocial' => 'ACME SA'],
        ]));

        $this->assertNull($resultado->condicionIvaId);
    }

    public function test_con_condicion_iva_respuesta_null_deja_resultado_original_intacto(): void
    {
        $original = $this->resultadoBase();

        $resultado = ResultadoConsultaPadron::conCondicionIva($original, null);

        $this->assertSame($original, $resultado);
        $this->assertNull($resultado->condicionIvaId);
        $this->assertSame('ACME SA', $resultado->razonSocial);
    }
}
