<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Un usuario sin `auditoria.ver` recibe 403 al acceder a /auditoria y sus endpoints AJAX (FR-010). */
class AuditoriaPermisoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_permiso_recibe_403_en_index(): void
    {
        $this->get(route('auditoria.index'))->assertForbidden();
    }

    public function test_sin_permiso_recibe_403_en_datatable(): void
    {
        $this->getJson(route('auditoria.data'))->assertForbidden();
    }

    public function test_sin_permiso_recibe_403_en_exportar(): void
    {
        $this->getJson(route('auditoria.exportar'))->assertForbidden();
    }
}
