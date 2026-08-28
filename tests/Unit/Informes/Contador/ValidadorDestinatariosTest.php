<?php

namespace Tests\Unit\Informes\Contador;

use App\Services\Informes\Contador\ValidadorDestinatarios;
use PHPUnit\Framework\TestCase;

class ValidadorDestinatariosTest extends TestCase
{
    public function test_parsea_una_sola_direccion(): void
    {
        $direcciones = (new ValidadorDestinatarios)->parsear('contador@estudio.com');

        $this->assertSame(['contador@estudio.com'], $direcciones);
    }

    public function test_parsea_varias_direcciones_separadas_por_coma(): void
    {
        $direcciones = (new ValidadorDestinatarios)->parsear('a@x.com, b@x.com,c@x.com');

        $this->assertSame(['a@x.com', 'b@x.com', 'c@x.com'], $direcciones);
    }

    public function test_rechaza_lista_vacia(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ValidadorDestinatarios)->parsear('');
    }

    public function test_rechaza_y_senala_la_direccion_invalida(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no-es-un-mail');
        (new ValidadorDestinatarios)->parsear('bien@x.com, no-es-un-mail');
    }
}
