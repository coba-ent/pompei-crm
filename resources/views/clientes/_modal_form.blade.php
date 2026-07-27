{{-- Modal de alta / edición de cliente (enviado por AJAX, sin recargar) --}}
<div class="modal fade" id="modal-cliente" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="form-cliente" novalidate>
                <input type="hidden" id="cliente-id" name="id" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="modal-cliente-titulo">Nuevo Cliente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body">

                    {{-- ====================== DATOS BÁSICOS ====================== --}}
                    <div class="row g-3">
                        {{-- ===== Columna izquierda: datos básicos + personas de contacto ===== --}}
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label class="form-label">Cliente <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nombre" required
                                       placeholder="Nombre de la Empresa o Nombre y Apellido">
                                <div class="invalid-feedback" data-field="nombre"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Nombre</label>
                                <input type="text" class="form-control" name="nombre_pila">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Apellido</label>
                                <input type="text" class="form-control" name="apellido">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Cel.</label>
                                <input type="text" class="form-control" name="telefono_celular" placeholder="9 11 2345-678">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Teléfono</label>
                                <input type="text" class="form-control" name="telefono">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email">
                                <div class="invalid-feedback" data-field="email"></div>
                            </div>

                            {{-- Personas de contacto (dinámico) --}}
                            <div id="contactos-container"></div>

                            <button type="button" class="btn btn-link p-0 mt-1" id="btn-agregar-contacto">
                                <i class="fas fa-plus me-1"></i> Agregar Persona de Contacto
                            </button>
                        </div>

                        {{-- ===== Columna derecha: datos extra + campos personalizados + saldo ===== --}}
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label class="form-label">Apodo ML</label>
                                <input type="text" class="form-control" name="apodo_ml"
                                       placeholder="Apodo de Mercado Libre">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Página Web</label>
                                <input type="text" class="form-control" name="pagina_web">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Domicilio</label>
                                <input type="text" class="form-control" name="domicilio">
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-8">
                                    <label class="form-label">Provincia</label>
                                    <select class="form-select js-provincia" name="provincia" data-localidad-target="localidad">
                                        <option value="">Seleccionar</option>
                                        @foreach ($provincias as $prov)
                                            <option value="{{ $prov }}">{{ $prov }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-4">
                                    <label class="form-label">C.P.</label>
                                    <input type="text" class="form-control" name="cp">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Localidad</label>
                                <select class="form-select js-localidad" name="localidad" data-provincia="localidad">
                                    <option value="">Seleccionar</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Nota</label>
                                <textarea class="form-control" name="nota" rows="2"></textarea>
                            </div>

                            {{-- Campos adicionales del cliente (dinámico, sólo de ESTE cliente).
                                 Se arman en el front y se guardan asociados al cliente al guardar. --}}
                            <div id="campos-personalizados-container"></div>

                            {{-- Saldo inicial (plegable): apertura de la cuenta corriente
                                 del cliente — Fecha + Monto (Contagram). --}}
                            <div id="saldo-inicial-wrap" class="mb-3 d-none">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label">Fecha</label>
                                        <input type="date" class="form-control" name="saldo_inicial_fecha">
                                        <div class="invalid-feedback" data-field="saldo_inicial_fecha"></div>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label">Monto</label>
                                        <div class="input-group">
                                            <span class="input-group-text">$</span>
                                            <input type="number" step="0.01" class="form-control" name="saldo_inicial" placeholder="0">
                                        </div>
                                        <div class="invalid-feedback" data-field="saldo_inicial"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-3 mt-1">
                                <button type="button" class="btn btn-link p-0" id="btn-agregar-campo">
                                    <i class="fas fa-plus me-1"></i> Agregar Nuevo campo
                                </button>
                                <button type="button" class="btn btn-link p-0" id="btn-toggle-saldo">
                                    <i class="fas fa-plus me-1"></i> Saldo Inicial
                                </button>
                            </div>
                        </div>
                    </div>

                    <hr>

                    {{-- ====================== VENTAS ====================== --}}
                    <h6 class="fw-bold mb-3">
                        <i class="fas fa-tags me-2 text-primary"></i>Ventas
                        <i class="fas fa-question-circle text-info ms-1" style="cursor:help" data-bs-toggle="tooltip"
                           title="Los datos seleccionados aquí aparecerán por defecto en una nueva venta al elegir este cliente"></i>
                    </h6>
                    <div class="row g-3 mb-2">
                        <div class="col-md-6">
                            <label class="form-label">Categoría Ventas</label>
                            <select class="form-select" name="categoria_id">
                                <option value="">Seleccionar</option>
                                @foreach ($categorias as $categoria)
                                    <option value="{{ $categoria->id }}">{{ $categoria->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Descuento General (%)</label>
                            <input type="number" step="0.01" min="0" max="100" class="form-control" name="descuento_general_pct">
                            <div class="invalid-feedback" data-field="descuento_general_pct"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Lista de Precios</label>
                            <select class="form-select" name="lista_precio_id">
                                <option value="">Seleccionar</option>
                                @foreach ($listasPrecio as $lista)
                                    <option value="{{ $lista->id }}">{{ $lista->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nota para el Cliente</label>
                            <input type="text" class="form-control" name="nota_cliente">
                        </div>
                    </div>

                    <hr>

                    {{-- ================= DATOS DE FACTURACIÓN ================= --}}
                    <h6 class="fw-bold mb-3">
                        <i class="fas fa-file-invoice-dollar me-2 text-primary"></i>Datos de Facturación
                        <i class="fas fa-question-circle text-info ms-1" style="cursor:help" data-bs-toggle="tooltip"
                           title="Todos los comprobantes emitidos a este cliente vendrán por defecto con estos datos de Facturación"></i>
                    </h6>
                    <div class="row g-3">
                        {{-- Columna izquierda --}}
                        <div class="col-lg-6">
                            <div class="mb-3">
                                <label class="form-label">Razón social</label>
                                <input type="text" class="form-control" name="razon_social"
                                       placeholder="Nombre de la Empresa o Nombre y Apellido">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">N° de Doc.</label>
                                <div class="input-group">
                                    <select class="form-select" name="tipo_documento" style="max-width:120px">
                                        <option value="CUIT" selected>CUIT</option>
                                        <option value="CUIL">CUIL</option>
                                        <option value="DNI">DNI</option>
                                        <option value="Pasaporte">Pasaporte</option>
                                        <option value="CDI">CDI</option>
                                    </select>
                                    <input type="text" class="form-control" name="cuit" maxlength="11" placeholder="Sin guiones">
                                </div>
                                <div class="invalid-feedback d-block" data-field="cuit"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Condición de IVA</label>
                                <select class="form-select" name="condicion_iva_id" id="select-condicion-iva">
                                    <option value="">Seleccione una condición de IVA</option>
                                    @foreach ($condicionesIva as $condicion)
                                        <option value="{{ $condicion->id }}" data-requiere-cuit="{{ $condicion->requiere_cuit ? 1 : 0 }}">
                                            {{ $condicion->nombre }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="invalid-feedback" data-field="condicion_iva_id"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Comprobante por defecto</label>
                                <select class="form-select" name="tipo_comprobante_defecto">
                                    <option value="">Tipo</option>
                                    <option value="A">Factura A</option>
                                    <option value="B">Factura B</option>
                                    <option value="C">Factura C</option>
                                    <option value="E">Factura E</option>
                                </select>
                                <div class="invalid-feedback" data-field="tipo_comprobante_defecto"></div>
                            </div>
                        </div>

                        {{-- Columna derecha --}}
                        <div class="col-lg-6">
                            <div class="row g-2 mb-3">
                                <div class="col-7">
                                    <label class="form-label">Teléfono</label>
                                    <input type="text" class="form-control" name="telefono_fiscal">
                                </div>
                                <div class="col-5">
                                    <label class="form-label">Cel.</label>
                                    <input type="text" class="form-control" name="telefono_celular_fiscal">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Domicilio fiscal</label>
                                <input type="text" class="form-control" name="domicilio_fiscal">
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-8">
                                    <label class="form-label">Provincia</label>
                                    <select class="form-select js-provincia" name="provincia_fiscal" data-localidad-target="localidad_fiscal">
                                        <option value="">Seleccionar</option>
                                        @foreach ($provincias as $prov)
                                            <option value="{{ $prov }}">{{ $prov }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-4">
                                    <label class="form-label">C.P.</label>
                                    <input type="text" class="form-control" name="cp_fiscal">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Localidad</label>
                                <select class="form-select js-localidad" name="localidad_fiscal" data-provincia="localidad_fiscal">
                                    <option value="">Seleccionar</option>
                                </select>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btn-guardar-cliente">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Modal de confirmación de eliminación (US4) --}}
<div class="modal fade" id="modal-eliminar-cliente" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Eliminar cliente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                ¿Eliminar definitivamente este cliente? Esta acción no se puede deshacer.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-eliminar">Eliminar</button>
            </div>
        </div>
    </div>
</div>

{{-- Modal "Crear nuevo campo" (definición de campo personalizado — Contagram) --}}
<div class="modal fade" id="modal-nuevo-campo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Crear nuevo campo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nombre</label>
                    <input type="text" class="form-control" id="nuevo-campo-nombre">
                    <div class="invalid-feedback" id="nuevo-campo-error-nombre"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Tipo</label>
                    <select class="form-select" id="nuevo-campo-tipo">
                        <option value="texto">Texto</option>
                        <option value="numerico">Numérico</option>
                        <option value="fecha">Fecha</option>
                        <option value="opciones">Opciones</option>
                    </select>
                </div>
                {{-- Editor de opciones (sólo tipo "Opciones") --}}
                <div class="mb-2 d-none" id="nuevo-campo-opciones-wrap">
                    <label class="form-label">Opciones</label>
                    <div class="input-group mb-2">
                        <input type="text" class="form-control" id="nuevo-campo-opcion-input" placeholder="Nueva opción">
                        <button type="button" class="btn btn-primary" id="btn-agregar-opcion"><i class="fas fa-plus"></i></button>
                    </div>
                    <ul class="list-unstyled mb-0" id="nuevo-campo-opciones-lista"></ul>
                    <div class="invalid-feedback d-block" id="nuevo-campo-error-opciones"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" id="btn-guardar-campo">Guardar</button>
            </div>
        </div>
    </div>
</div>
