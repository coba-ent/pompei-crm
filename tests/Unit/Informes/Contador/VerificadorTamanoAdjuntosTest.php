<?php

namespace Tests\Unit\Informes\Contador;

use App\Services\Informes\Contador\VerificadorTamanoAdjuntos;
use Tests\TestCase;

class VerificadorTamanoAdjuntosTest extends TestCase
{
    public function test_no_lanza_con_adjuntos_chicos(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'chico_');
        file_put_contents($ruta, str_repeat('a', 1024));

        (new VerificadorTamanoAdjuntos)->verificar(['x.txt' => $ruta]);

        $this->addToAssertionCount(1);
        @unlink($ruta);
    }

    public function test_lanza_cuando_supera_el_limite_configurado(): void
    {
        config(['mail.limite_adjuntos_mb' => 1]);

        $ruta = tempnam(sys_get_temp_dir(), 'grande_');
        file_put_contents($ruta, str_repeat('a', 2 * 1024 * 1024));

        $this->expectException(\RuntimeException::class);

        try {
            (new VerificadorTamanoAdjuntos)->verificar(['x.zip' => $ruta]);
        } finally {
            @unlink($ruta);
        }
    }
}
