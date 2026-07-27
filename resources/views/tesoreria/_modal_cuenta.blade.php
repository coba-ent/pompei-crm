{{--
    Modal único de alta/edición de cuenta de tesorería (US2). En edición el
    select Tipo queda deshabilitado (FR-004) y aparecen los radios
    Mostrar/Ocultar + botón Eliminar; en alta sólo Nombre/Tipo/Saldo/Fecha.
--}}
<div class="modal fade" id="modal-cuenta-tesoreria" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="form-cuenta-tesoreria">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-cuenta-tesoreria-titulo">Nueva Cuenta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="cuenta-id" name="id">

                    <div class="mb-3">
                        <label class="form-label">Fecha</label>
                        <input type="date" class="form-control" id="cuenta-saldo-inicial-fecha" name="saldo_inicial_fecha" required>
                        <div class="invalid-feedback" data-error="saldo_inicial_fecha"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Saldo Inicial</label>
                        <input type="number" step="0.01" class="form-control" id="cuenta-saldo-inicial" name="saldo_inicial" value="0">
                        <div class="invalid-feedback" data-error="saldo_inicial"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Nombre de la Cuenta</label>
                        <input type="text" class="form-control" id="cuenta-nombre" name="nombre" required maxlength="255">
                        <div class="invalid-feedback" data-error="nombre"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Seleccionar Tipo de Cuenta</label>
                        <select class="form-select" id="cuenta-tipo" name="tipo" required>
                            <option value="a_cobrar">A Cobrar</option>
                            <option value="a_pagar">A Pagar</option>
                            <option value="banco">Banco</option>
                            <option value="efectivo">Efectivo</option>
                        </select>
                        <div class="invalid-feedback" data-error="tipo"></div>
                    </div>

                    <div class="mb-3 d-none" id="cuenta-visible-wrap">
                        <label class="form-label d-block">Visibilidad</label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="visible" id="cuenta-visible-mostrar" value="1" checked>
                            <label class="form-check-label" for="cuenta-visible-mostrar">Mostrar Cuenta</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="visible" id="cuenta-visible-ocultar" value="0">
                            <label class="form-check-label" for="cuenta-visible-ocultar">Ocultar Cuenta</label>
                        </div>
                    </div>

                    <div class="alert alert-secondary d-none" id="cuenta-sistema-aviso">
                        Esta es una cuenta del sistema y no puede editarse ni eliminarse.
                    </div>
                </div>
                <div class="modal-footer justify-content-between">
                    <button type="button" class="btn btn-outline-danger d-none" id="btn-eliminar-cuenta">Eliminar</button>
                    <div class="ms-auto">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="btn-guardar-cuenta">Crear</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
