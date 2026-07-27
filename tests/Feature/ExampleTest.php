<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_un_visitante_sin_sesion_es_redirigido_al_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
