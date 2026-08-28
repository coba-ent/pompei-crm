{{-- Enviar Información a tu Contador por Correo (spec 087) --}}
<div class="modal fade" id="modal-envio-contador" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <form id="form-envio-contador">
                <div class="modal-header">
                    <h5 class="modal-title">Enviar Información a tu Contador por Correo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-md-7">
                            <div class="mb-3">
                                <label class="form-label">Mail</label>
                                <input type="text" class="form-control" name="destinatarios" id="ec-destinatarios" placeholder="contador@estudio.com.ar">
                                <div class="form-text">Separar con una coma (,) direcciones de mail adicionales</div>
                                <div class="invalid-feedback" data-field="destinatarios"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Asunto del Correo</label>
                                <input type="text" class="form-control" name="asunto" id="ec-asunto">
                                <div class="invalid-feedback" data-field="asunto"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Contenido del Correo</label>
                                <textarea class="form-control" name="cuerpo" id="ec-cuerpo" rows="8" style="resize:vertical"></textarea>
                                <div class="invalid-feedback" data-field="cuerpo"></div>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="copia_remitente" id="ec-copia-remitente">
                                <label class="form-check-label" for="ec-copia-remitente">Enviar una copia a mi Mail</label>
                            </div>
                            <div>
                                <button type="button" class="btn btn-warning btn-sm" id="ec-btn-adjuntar">
                                    <i class="fas fa-paperclip me-1"></i> Adjuntar
                                </button>
                                <input type="file" id="ec-input-adjuntos" name="adjuntos_propios[]" multiple class="d-none">
                                <ul class="list-unstyled mt-2 mb-0 small" id="ec-lista-adjuntos-propios"></ul>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label">Año</label>
                                    <select class="form-select" id="ec-anio" name="anio" style="width:100%">
                                        <option value="">Año</option>
                                        @foreach ($anios as $anio)
                                            <option value="{{ $anio }}">{{ $anio }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Mes</label>
                                    <select class="form-select" id="ec-mes" name="mes" style="width:100%">
                                        <option value="">Mes</option>
                                        @foreach (['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'] as $num => $nombre)
                                            <option value="{{ (int) $num }}">{{ $nombre }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="incluye_electronicas" id="ec-electronicas" checked>
                                <label class="form-check-label" for="ec-electronicas">Facturas Electrónicas</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="incluye_manuales" id="ec-manuales">
                                <label class="form-check-label" for="ec-manuales">
                                    Facturas Manuales
                                    <i class="fas fa-question-circle text-info ms-1" data-bs-toggle="tooltip"
                                       title="Comprobantes de venta sin CAE aprobado por ARCA (no enviados o rechazados)."></i>
                                </label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="incluye_pdfs" id="ec-pdfs">
                                <label class="form-check-label" for="ec-pdfs">PDF factura de ventas</label>
                            </div>
                            <div class="invalid-feedback d-block" data-field="incluye_electronicas" style="display:none !important;"></div>

                            <label class="form-label small text-muted mb-1">Archivos Adjuntos</label>
                            <div class="border rounded p-2" id="ec-panel-adjuntos" style="min-height:80px">
                                <p class="text-muted small mb-0" id="ec-panel-vacio">Elegí un período para ver los archivos que se van a enviar.</p>
                                <ul class="list-unstyled mb-0" id="ec-lista-adjuntos"></ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-danger" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success" id="ec-btn-enviar">
                        <i class="fas fa-envelope me-1"></i> Enviar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
