<div class="modal fade" id="modal-acciones-masivas" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="form-acciones-masivas">
                <div class="modal-header">
                    <h5 class="modal-title">Acciones Masivas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">Realizá acciones masivas sobre el producto seleccionado.</p>

                    <div class="mb-3">
                        <label class="form-label">Elegí una Acción</label>
                        <select class="form-select" id="masiva-accion" name="accion">
                            <option value="">Elegí una Acción</option>
                            <option value="precio_venta">Modificar Precio de Venta</option>
                            <option value="costo">Modificar Costo</option>
                            <option value="mostrar_ventas">Mostrar en Ventas</option>
                            <option value="no_mostrar_ventas">No Mostrar en Ventas</option>
                            <option value="mostrar_compras">Mostrar en Compras</option>
                            <option value="no_mostrar_compras">No Mostrar en Compras</option>
                            <option value="activo">Modificar Estado</option>
                            <option value="iva">Modificar IVA por defecto</option>
                            <option value="tipo_producto_id">Modificar Tipo de Producto</option>
                            <option value="proveedor_id">Modificar Proveedor</option>
                            <option value="eliminar">Eliminar Masivamente</option>
                        </select>
                        <div class="invalid-feedback" data-field="accion"></div>
                    </div>

                    <div class="mb-3 d-none" data-valor="activo">
                        <label class="form-label">Estado</label>
                        <select class="form-select" name="valor_activo">
                            <option value="1">Activo</option>
                            <option value="0">Inactivo</option>
                        </select>
                    </div>

                    <div class="mb-3 d-none" data-valor="proveedor_id">
                        <label class="form-label">Proveedor</label>
                        <select class="form-select" id="masiva-proveedor" name="valor_proveedor_id"></select>
                    </div>

                    <div class="alert alert-warning d-none" id="masiva-eliminar-aviso">
                        Los productos con operaciones asociadas no se eliminarán; sólo se pueden inactivar.
                    </div>

                    <div class="d-none" id="masiva-resultado-detalle"></div>
                    <div class="invalid-feedback d-block" data-field="valor"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btn-confirmar-acciones-masivas" disabled>Confirmar</button>
                </div>
            </form>
        </div>
    </div>
</div>
