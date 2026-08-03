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
                'datosGenerales' => [
                    'razonSocial' => 'ACME SA',
                    'domicilioFiscal' => ['direccion' => 'AV CORRIENTES 1234', 'localidad' => 'CABA'],
                    'estadoClave' => 'ACTIVO',
                ],
                'datosRegimenGeneral' => [
                    'impuesto' => [
                        ['descripcionImpuesto' => $condicionIva],
                    ],
                ],
            ],
        ]));
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
}
