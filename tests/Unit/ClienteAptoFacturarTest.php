<?php

namespace Tests\Unit;

use App\Models\Cliente;
use App\Models\CondicionIva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClienteAptoFacturarTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_es_apto_sin_condicion_iva(): void
    {
        $cliente = new Cliente(['nombre' => 'Sin condición']);

        $this->assertFalse($cliente->esAptoParaFacturar());
    }

    public function test_es_apto_con_condicion_que_no_requiere_cuit(): void
    {
        $consumidorFinal = CondicionIva::create([
            'nombre' => 'Consumidor Final',
            'requiere_cuit' => false,
        ]);

        $cliente = Cliente::create([
            'nombre' => 'Juan',
            'condicion_iva_id' => $consumidorFinal->id,
        ]);

        $this->assertTrue($cliente->fresh()->esAptoParaFacturar());
    }

    public function test_no_es_apto_si_la_condicion_requiere_cuit_y_falta(): void
    {
        $ri = CondicionIva::create([
            'nombre' => 'Responsable Inscripto',
            'requiere_cuit' => true,
        ]);

        $cliente = Cliente::create([
            'nombre' => 'Empresa SA',
            'condicion_iva_id' => $ri->id,
            'cuit' => null,
        ]);

        $this->assertTrue($cliente->fresh()->requiereCuitParaFacturar());
        $this->assertFalse($cliente->fresh()->esAptoParaFacturar());
    }

    public function test_es_apto_si_la_condicion_requiere_cuit_y_esta_presente(): void
    {
        $mono = CondicionIva::create([
            'nombre' => 'Monotributista',
            'requiere_cuit' => true,
        ]);

        $cliente = Cliente::create([
            'nombre' => 'Pyme',
            'condicion_iva_id' => $mono->id,
            'cuit' => '20111111112',
        ]);

        $this->assertTrue($cliente->fresh()->esAptoParaFacturar());
    }
}
