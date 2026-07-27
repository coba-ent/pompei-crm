<div class="modal fade" id="modal-masiva-precios" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form id="form-masiva-precios">
                <input type="hidden" id="masiva-precios-accion" name="accion">
                <div class="modal-header">
                    <h5 class="modal-title" id="masiva-precios-titulo">Edición Masiva de Precios de Venta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="text-center fw-bold">
                        Vas a editar los <span id="masiva-precios-cantidad">0</span> productos seleccionados
                    </p>

                    <div class="d-flex justify-content-center gap-3 mb-4">
                        <button type="button" class="btn btn-primary rounded-pill js-modo-precios" data-modo="porcentaje">Cambiar por Porcentaje</button>
                        <button type="button" class="btn btn-outline-primary rounded-pill js-modo-precios" data-modo="fijo">Cambiar por Valor Fijo</button>
                    </div>

                    <div id="masiva-precios-campos"></div>

                    <div class="d-flex align-items-center justify-content-between border-top pt-3">
                        <label class="form-label mb-0" id="masiva-precios-redondear-label">Redondear los precios modificados al primer entero</label>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="masiva-precios-redondear">
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success" id="btn-actualizar-precios">Actualizar Precios</button>
                </div>
            </form>
        </div>
    </div>
</div>
