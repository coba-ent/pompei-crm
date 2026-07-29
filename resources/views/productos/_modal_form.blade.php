{{-- Modal de alta / edición de producto (enviado por AJAX, sin recargar) --}}
<div class="modal fade" id="modal-producto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form id="form-producto" novalidate>
                <input type="hidden" id="producto-id" name="id" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="modal-producto-titulo">Nuevo Producto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body">

                    {{-- ====================== DATOS BÁSICOS ====================== --}}
                    <h6 class="text-primary fw-bold mb-3">Datos básicos</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-lg-6">
                            <label class="form-label">Nombre <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="nombre" required
                                   placeholder="Nombre del producto o servicio">
                            <div class="invalid-feedback" data-field="nombre"></div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label">Código/SKU</label>
                            <input type="text" class="form-control" name="codigo" placeholder="Opcional, único">
                            @if (!empty($ultimoCodigo))
                                <small class="text-muted">Último código generado: {{ $ultimoCodigo }}</small>
                            @endif
                            <div class="invalid-feedback" data-field="codigo"></div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label">Tipo</label>
                            <select class="form-select" name="tipo" id="producto-tipo">
                                <option value="producto" selected>Producto</option>
                                <option value="servicio">Servicio</option>
                            </select>
                        </div>
                        <div class="col-lg-3" id="tipo-producto-wrap">
                            <label class="form-label d-flex align-items-center gap-1">
                                <span class="flex-grow-1">Tipo de Producto</span>
                                <a href="#" id="btn-renombrar-tipo-producto" class="text-primary d-none" title="Renombrar"><i class="fas fa-pencil-alt"></i></a>
                                <a href="#" id="btn-eliminar-tipo-producto" class="text-danger d-none" title="Eliminar"><i class="fas fa-trash-alt"></i></a>
                            </label>
                            <select class="form-select" name="tipo_producto_id" id="producto-tipo-producto"></select>
                            <div class="invalid-feedback" data-field="tipo_producto_id"></div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label">Proveedor</label>
                            <select class="form-select" name="proveedor_id" id="producto-proveedor">
                                <option value="">Elija Proveedor</option>
                                @foreach ($proveedores ?? [] as $proveedor)
                                    <option value="{{ $proveedor->id }}">{{ $proveedor->nombre }}</option>
                                @endforeach
                            </select>
                            <div class="invalid-feedback" data-field="proveedor_id"></div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label d-block">Estado</label>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="activo" id="estado-activo" value="1" checked>
                                <label class="form-check-label" for="estado-activo">Activo</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="activo" id="estado-inactivo" value="0">
                                <label class="form-check-label" for="estado-inactivo">Inactivo</label>
                            </div>
                            <div class="invalid-feedback d-block" data-field="activo"></div>
                        </div>
                        {{-- Stock inicial: sólo al CREAR un producto de Tipo=Producto. Genera un
                             movimiento "Registro inicial" (equivalente a Contagram). No se muestra
                             al editar: el stock ya cargado se ajusta desde la acción "Ajuste de Stock". --}}
                        <div class="col-lg-3" id="stock-inicial-wrap">
                            <label class="form-label">Stock inicial</label>
                            <input type="number" step="0.001" min="0" class="form-control" name="stock_inicial" value="0">
                            <div class="invalid-feedback" data-field="stock_inicial"></div>
                        </div>
                        <div class="col-lg-3" id="stock-inicial-deposito-wrap">
                            <label class="form-label">Depósito</label>
                            <select class="form-select" name="stock_inicial_deposito_id">
                                @include('elements._options_depositos', ['depositos' => $depositos])
                            </select>
                            <div class="invalid-feedback" data-field="stock_inicial_deposito_id"></div>
                        </div>
                        <div class="col-lg-6">
                            <label class="form-label">Descripción</label>
                            <textarea class="form-control" name="descripcion" rows="2"></textarea>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label">Imagen</label>
                            <input type="file" class="form-control" name="imagen" accept="image/*" id="producto-imagen">
                            <input type="hidden" name="imagen_eliminar" id="producto-imagen-eliminar" value="0">
                            <div class="invalid-feedback" data-field="imagen"></div>
                            <div class="mt-2 d-none" id="producto-imagen-preview-wrap">
                                <img src="" alt="Imagen del producto" id="producto-imagen-preview"
                                     class="img-thumbnail" style="max-height:80px">
                                <button type="button" class="btn btn-sm btn-outline-danger ms-1" id="btn-quitar-imagen">
                                    <i class="fas fa-times"></i> Quitar
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- ====================== ECONÓMICOS ====================== --}}
                    <h6 class="text-primary fw-bold mb-3">Económicos</h6>
                    <div class="row g-3 mb-2">
                        <div class="col-lg-3">
                            <label class="form-label">Precio de venta</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="precio_venta" value="0">
                            <div class="invalid-feedback" data-field="precio_venta"></div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label">IVA por defecto (ventas)</label>
                            <select class="form-select" name="iva_venta_pct">
                                @foreach (\App\Models\Producto::OPCIONES_IVA as $valor => $op)
                                    <option value="{{ $valor }}" @selected($valor === '21')>{{ $op['label'] }}</option>
                                @endforeach
                            </select>
                            <div class="invalid-feedback" data-field="iva_venta_pct"></div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label">Costo</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="costo" value="0">
                            <div class="invalid-feedback" data-field="costo"></div>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label">IVA por defecto (compras)</label>
                            <select class="form-select" name="iva_compra_pct">
                                @foreach (\App\Models\Producto::OPCIONES_IVA as $valor => $op)
                                    <option value="{{ $valor }}" @selected($valor === '21')>{{ $op['label'] }}</option>
                                @endforeach
                            </select>
                            <div class="invalid-feedback" data-field="iva_compra_pct"></div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-lg-3">
                            <div class="form-check">
                                <input type="hidden" name="mostrar_en_ventas" value="0">
                                <input class="form-check-input" type="checkbox" name="mostrar_en_ventas" value="1" id="mostrar-ventas" checked>
                                <label class="form-check-label" for="mostrar-ventas">Mostrar en ventas</label>
                            </div>
                        </div>
                        <div class="col-lg-3">
                            <div class="form-check">
                                <input type="hidden" name="mostrar_en_compras" value="0">
                                <input class="form-check-input" type="checkbox" name="mostrar_en_compras" value="1" id="mostrar-compras" checked>
                                <label class="form-check-label" for="mostrar-compras">Mostrar en compras</label>
                            </div>
                        </div>
                    </div>

                    {{-- ====================== OPCIONES AVANZADAS ====================== --}}
                    {{-- En Contagram, Variantes y Listas de precio viven dentro de
                         "Opciones Avanzadas", no en la vista principal del modal. --}}
                    <div class="border-top pt-2 mt-2">
                        <button type="button" class="btn btn-link text-decoration-none px-0 fw-bold" data-bs-toggle="collapse"
                                data-bs-target="#opciones-avanzadas" aria-expanded="false">
                            <i class="fas fa-sliders-h me-1"></i> Opciones Avanzadas
                        </button>
                    </div>
                    <div class="collapse" id="opciones-avanzadas">
                        <div class="pt-2">

                            {{-- ---------- Listas de precio (US5) ---------- --}}
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="text-primary fw-bold mb-0">Listas de precio</h6>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="toggle-listas-precio">
                                    <label class="form-check-label" for="toggle-listas-precio">Lista de Precios $</label>
                                </div>
                            </div>
                            <div id="listas-precio-wrap" class="mb-3 d-none">
                                <div id="listas-precio-container" class="row g-2"></div>
                                <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="btn-agregar-lista-precio">
                                    <i class="fas fa-plus me-1"></i> Agregar Lista de Precios
                                </button>
                            </div>

                            {{-- ---------- Variantes (US4) ----------
                                 OCULTO: Contagram no expone "Variantes" en el modal de producto
                                 (el propio tooltip del Nombre sugiere cargar talle/color en el nombre).
                                 Se deja la infraestructura (`producto_variantes`, usada por la sync de
                                 TiendaNube) pero sin UI de alta. Para reactivar, quitar el d-none. --}}
                            <div id="seccion-variantes" class="d-none">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="text-primary fw-bold mb-0">Variantes</h6>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn-agregar-variante">
                                        <i class="fas fa-plus me-1"></i> Agregar variante
                                    </button>
                                </div>
                                <div id="variantes-container"></div>
                            </div>

                        </div>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btn-guardar-producto">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Plantilla de fila de variante (clonada por JS) --}}
<template id="tpl-variante">
    <div class="row g-2 mb-2 align-items-end js-variante border rounded p-2">
        <input type="hidden" class="js-variante-id" data-name="id" value="">
        <div class="col-md-2">
            <label class="form-label mb-1 fs-13">Talle</label>
            <input type="text" class="form-control form-control-sm" data-name="talle">
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1 fs-13">Color</label>
            <input type="text" class="form-control form-control-sm" data-name="color">
        </div>
        <div class="col-md-3">
            <label class="form-label mb-1 fs-13">Nombre/etiqueta</label>
            <input type="text" class="form-control form-control-sm" data-name="nombre">
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1 fs-13">SKU</label>
            <input type="text" class="form-control form-control-sm" data-name="sku">
        </div>
        <div class="col-md-2">
            <label class="form-label mb-1 fs-13">Precio extra</label>
            <input type="number" step="0.01" class="form-control form-control-sm" data-name="precio_extra">
        </div>
        <div class="col-md-1 text-end">
            <button type="button" class="btn btn-sm btn-outline-danger js-quitar-variante"><i class="fas fa-times"></i></button>
        </div>
    </div>
</template>
