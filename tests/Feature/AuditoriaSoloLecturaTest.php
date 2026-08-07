<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** FR-007: logs_auditoria no admite UPDATE/DELETE de aplicación — no debe haber rutas para eso. */
class AuditoriaSoloLecturaTest extends TestCase
{
    protected bool $autenticado = false;

    public function test_no_existen_rutas_de_edicion_o_borrado_para_auditoria(): void
    {
        $rutasAuditoria = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->getName() ?? '', 'auditoria.'));

        $this->assertTrue($rutasAuditoria->isNotEmpty());

        $metodosNoPermitidos = $rutasAuditoria->flatMap(fn ($r) => $r->methods())
            ->intersect(['PUT', 'PATCH', 'DELETE']);

        $this->assertTrue($metodosNoPermitidos->isEmpty(), 'No debe haber rutas PUT/PATCH/DELETE para auditoria.*');
    }
}
