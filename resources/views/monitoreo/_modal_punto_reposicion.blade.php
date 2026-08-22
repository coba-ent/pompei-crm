{{-- spec 073 — edición del punto de reposición desde el propio panel, sin salir de la pantalla. --}}
<div class="modal fade" id="modal-punto-reposicion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="form-punto-reposicion">
                <div class="modal-header">
                    <h5 class="modal-title">Punto de reposición</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2" id="punto-reposicion-producto-nombre"></p>
                    <input type="hidden" name="producto_id" id="punto-reposicion-producto-id">
                    <label class="form-label">Cantidad mínima deseada</label>
                    <input type="number" step="1" min="0" class="form-control" name="punto_reposicion" id="punto-reposicion-valor" placeholder="Sin control">
                    <div class="form-text">Vacío o 0: el producto deja de controlarse.</div>
                    <div class="invalid-feedback d-block" data-field="punto_reposicion"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>
