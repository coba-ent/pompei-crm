{{--
    Ficha de proveedor del informe — **sólo lectura** (FR-037).

    No se reutiliza el modal de Proveedores a propósito (research R9): ese trae formulario y
    botón de guardar, y un informe no puede ser una puerta lateral para editar el maestro. Acá
    no hay ni un `<input>` ni un botón de acción: sólo el botón de cerrar.
--}}
<div class="modal fade" id="modal-ficha-proveedor" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ficha-titulo">Proveedor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    @foreach ([
                        'nombre' => 'Nombre',
                        'apellido' => 'Apellido',
                        'email' => 'Email',
                        'telefono' => 'Teléfono',
                        'celular' => 'Cel.',
                        'pagina_web' => 'Página Web',
                        'domicilio' => 'Domicilio',
                        'localidad' => 'Localidad',
                        'provincia' => 'Provincia',
                        'cp' => 'C.P.',
                        'condicion_iva' => 'Condición de IVA',
                        'comprobante_defecto' => 'Comprobante por defecto',
                    ] as $clave => $etiqueta)
                        <div class="col-md-4">
                            <span class="text-muted d-block small">{{ $etiqueta }}</span>
                            <span data-ficha="{{ $clave }}">&mdash;</span>
                        </div>
                    @endforeach
                    <div class="col-12">
                        <span class="text-muted d-block small">Nota</span>
                        <span data-ficha="nota">&mdash;</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>
