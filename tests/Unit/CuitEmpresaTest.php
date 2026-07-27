<?php

namespace Tests\Unit;

use App\Http\Requests\PerfilUpdateRequest;
use App\Models\CondicionIva;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CuitEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private function validar(array $datosCuit): \Illuminate\Contracts\Validation\Validator
    {
        $request = new PerfilUpdateRequest;
        $condicion = CondicionIva::create(['nombre' => 'Responsable Inscripto']);

        $datos = array_merge([
            'razon_social' => 'Emisor de Prueba',
            'condicion_iva_id' => $condicion->id,
        ], $datosCuit);

        return Validator::make($datos, $request->rules());
    }

    public function test_rechaza_cuit_con_digito_verificador_invalido(): void
    {
        $validator = $this->validar(['cuit' => '20111111113']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('cuit', $validator->errors()->toArray());
    }

    public function test_acepta_cuit_valido(): void
    {
        $validator = $this->validar(['cuit' => '20111111112']);

        $this->assertFalse($validator->fails());
    }

    public function test_rechaza_sin_cuit(): void
    {
        $validator = $this->validar(['cuit' => '']);

        $this->assertTrue($validator->fails());
    }
}
