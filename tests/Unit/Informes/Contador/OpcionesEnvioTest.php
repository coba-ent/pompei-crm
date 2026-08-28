<?php

namespace Tests\Unit\Informes\Contador;

use App\Services\Informes\Contador\OpcionesEnvio;
use PHPUnit\Framework\TestCase;

class OpcionesEnvioTest extends TestCase
{
    public function test_se_puede_construir_con_solo_electronicas(): void
    {
        $o = new OpcionesEnvio(incluyeElectronicas: true, incluyeManuales: false, incluyePdfs: false);
        $this->assertTrue($o->incluyeElectronicas);
    }

    public function test_se_puede_construir_con_solo_manuales(): void
    {
        $o = new OpcionesEnvio(incluyeElectronicas: false, incluyeManuales: true, incluyePdfs: false);
        $this->assertTrue($o->incluyeManuales);
    }

    public function test_rechaza_ambas_destildadas(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OpcionesEnvio(incluyeElectronicas: false, incluyeManuales: false, incluyePdfs: false);
    }
}
