{{-- Modal "Ver Producto": solo lectura, fiel a Contagram (informe_contagram_base_de_datos.md §4.7). --}}
<div class="modal fade" id="modal-producto-ver" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ver Producto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
                <div class="d-flex align-items-start gap-3 mb-3">
                    <img src="" alt="" id="ver-producto-imagen" class="rounded border d-none" style="width:88px;height:88px;object-fit:cover">
                    <div class="flex-grow-1">
                        <h4 class="mb-1" id="ver-producto-nombre">&mdash;</h4>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="badge bg-light text-dark border" id="ver-producto-codigo"></span>
                            <span class="badge" id="ver-producto-estado"></span>
                            <span class="badge bg-light text-dark border" id="ver-producto-tipo"></span>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="text-muted fs-13">Tipo de Producto</div>
                        <div class="fw-semibold" id="ver-producto-tipo-producto">&mdash;</div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted fs-13">Proveedor</div>
                        <div class="fw-semibold" id="ver-producto-proveedor">&mdash;</div>
                    </div>
                    <div class="col-md-4" id="ver-producto-stock-wrap">
                        <div class="text-muted fs-13">Stock</div>
                        <div class="fw-semibold" id="ver-producto-stock">&mdash;</div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted fs-13">Costo</div>
                        <div class="fw-semibold" id="ver-producto-costo">&mdash;</div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted fs-13">Precio de Venta</div>
                        <div class="fw-semibold text-success" id="ver-producto-precio-venta">&mdash;</div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted fs-13">IVA por defecto (ventas)</div>
                        <div class="fw-semibold" id="ver-producto-iva-venta">&mdash;</div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted fs-13">IVA por defecto (compras)</div>
                        <div class="fw-semibold" id="ver-producto-iva-compra">&mdash;</div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted fs-13">Mostrar en</div>
                        <div class="fw-semibold" id="ver-producto-mostrar-en">&mdash;</div>
                    </div>
                </div>

                <div class="mb-3 d-none" id="ver-producto-listas-wrap">
                    <div class="text-muted fs-13 mb-1">Lista de Precios</div>
                    <table class="table table-sm table-borderless mb-0">
                        <tbody id="ver-producto-listas-body"></tbody>
                    </table>
                </div>

                <div id="ver-producto-descripcion-wrap" class="d-none">
                    <div class="text-muted fs-13 mb-1">Descripción</div>
                    <p class="mb-0" id="ver-producto-descripcion"></p>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
                <button type="button" class="btn btn-primary" id="btn-ver-editar">
                    <i class="fas fa-pencil-alt me-1"></i> Editar
                </button>
            </div>
        </div>
    </div>
</div>
