<div class="modal fade" id="modal-retencion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nueva Retención</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Fecha</label>
                    {{-- dd/mm/aaaa: ver `resources/js/fecha-ar.js`. Viaja ISO al backend. --}}
                    <input type="text" class="form-control" id="retencion-fecha" data-fecha-ar>
                </div>
                <div class="mb-3">
                    <label class="form-label">Monto</label>
                    <input type="number" step="0.01" class="form-control" id="retencion-monto">
                </div>
                <div class="mb-3">
                    <label class="form-label">Elija Tipo</label>
                    <select id="retencion-tipo" class="form-select" style="width:100%">
                        <option value="Ganancias">Ganancias</option>
                        <option value="IVA">IVA</option>
                        <option value="Seguridad Social">Seguridad Social</option>
                        <option value="Sellos">Sellos</option>
                        <option value="Ingresos Brutos - CABA">Ingresos Brutos - CABA</option>
                        <option value="Ingresos Brutos - Buenos Aires">Ingresos Brutos - Buenos Aires</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">N° de Comprobante</label>
                    <input type="text" class="form-control" id="retencion-comprobante">
                </div>
                <div class="mb-3">
                    <label class="form-label">Descripción</label>
                    <textarea class="form-control" id="retencion-descripcion" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-guardar-retencion">Crear</button>
            </div>
        </div>
    </div>
</div>
