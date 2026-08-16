<div class="modal fade" id="modal-ingreso" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-ingreso-titulo">Nuevo Ingreso</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ingreso-id">
                <div class="mb-3">
                    <label class="form-label">Fecha</label>
                    {{-- dd/mm/aaaa: ver `resources/js/fecha-ar.js`. Viaja ISO al backend. --}}
                    <input type="text" class="form-control" id="ingreso-fecha" data-fecha-ar>
                </div>
                <div class="mb-3">
                    <label class="form-label">Monto ($)</label>
                    <input type="number" step="0.01" class="form-control" id="ingreso-monto">
                </div>
                <div class="mb-3">
                    <label class="form-label d-flex align-items-center gap-1">
                        <span class="flex-grow-1">Categoría</span>
                        <a href="#" id="btn-renombrar-categoria-ingreso" class="text-primary d-none" title="Renombrar"><i class="fas fa-pencil-alt"></i></a>
                        <a href="#" id="btn-eliminar-categoria-ingreso" class="text-danger d-none" title="Eliminar"><i class="fas fa-trash-alt"></i></a>
                    </label>
                    <select id="ingreso-categoria" class="form-select" style="width:100%"></select>
                </div>
                <div class="mb-3" id="ingreso-cuenta-wrapper">
                    <label class="form-label">Medio de Cobro</label>
                    <select id="ingreso-cuenta" class="form-select" style="width:100%"></select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Descripción</label>
                    <textarea class="form-control" id="ingreso-descripcion" rows="2"></textarea>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="ingreso-pendiente">
                    <label class="form-check-label" for="ingreso-pendiente">Marcar como pendiente</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-guardar-ingreso">Crear</button>
            </div>
        </div>
    </div>
</div>
