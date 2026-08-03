@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-12 mb-2">
                <h4 class="mb-0 text-primary fw-bold">Mensajería</h4>
                <p class="text-muted mb-0">Preguntas y mensajes post-venta de Mercado Libre, en un solo lugar.</p>
            </div>
        </div>

        <div class="card overflow-hidden mensajeria-card">
            <div class="card-body p-0">
                <div class="row gx-0">

                    <div class="col-lg-4 mensajeria-bandeja">
                        <div class="mensajeria-bandeja-header">
                            <h6 class="mb-0 fw-semibold">Conversaciones</h6>
                            <div class="input-group input-group-sm mensajeria-buscador">
                                <span class="input-group-text bg-transparent border-end-0"><i class="fas fa-search"></i></span>
                                <input type="search" id="mensajeria-buscador" class="form-control border-start-0" placeholder="Buscar comprador...">
                            </div>
                        </div>
                        <div class="mensajeria-lista-wrapper">
                            <table id="tabla-mensajeria" class="table mensajeria-tabla" style="width:100%">
                                <thead class="visually-hidden">
                                    <tr>
                                        <th>Conversación</th>
                                        <th>Estado</th>
                                        <th>Fecha</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="col-lg-8 d-flex flex-column mensajeria-panel">
                        <div id="mensajeria-sin-seleccion" class="mensajeria-vacio">
                            <i class="fas fa-comments"></i>
                            <p class="mb-0">Elegí una conversación de la bandeja para ver el historial.</p>
                        </div>
                        <div id="mensajeria-conversacion" class="d-none d-flex flex-column flex-grow-1 mensajeria-conversacion">
                            <div class="mensajeria-conversacion-header">
                                <div class="mensajeria-avatar" id="mensajeria-conversacion-avatar">?</div>
                                <div class="flex-grow-1 min-w-0">
                                    <h6 class="mb-0 text-truncate" id="mensajeria-conversacion-titulo">—</h6>
                                    <small class="text-muted text-truncate d-block" id="mensajeria-conversacion-subtitulo"></small>
                                </div>
                                <span class="badge mensajeria-badge-estado" id="mensajeria-conversacion-estado"></span>
                            </div>

                            <div id="mensajeria-mensajes" class="mensajeria-mensajes"></div>

                            <div id="mensajeria-sugerencia" class="mensajeria-sugerencia d-none">
                                <div id="mensajeria-sugerencia-generando" class="mensajeria-sugerencia-estado d-none">
                                    <i class="fas fa-spinner fa-spin me-1"></i> Generando sugerencia con IA...
                                </div>
                                <div id="mensajeria-sugerencia-error" class="mensajeria-sugerencia-estado d-none">
                                    <span id="mensajeria-sugerencia-error-texto"></span>
                                    <button type="button" id="btn-reintentar-sugerencia" class="btn btn-sm btn-outline-secondary ms-2">Reintentar</button>
                                </div>
                                <div id="mensajeria-sugerencia-lista" class="mensajeria-sugerencia-estado d-none">
                                    <div class="mensajeria-sugerencia-texto">
                                        <i class="fas fa-robot me-1"></i>
                                        <span id="mensajeria-sugerencia-texto"></span>
                                    </div>
                                    <button type="button" id="btn-usar-sugerencia" class="btn btn-sm btn-outline-primary mt-1">Usar sugerencia</button>
                                </div>
                                <div id="mensajeria-sugerencia-pedir" class="mensajeria-sugerencia-estado d-none">
                                    <button type="button" id="btn-pedir-sugerencia" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-robot me-1"></i> Pedir sugerencia de respuesta
                                    </button>
                                </div>
                            </div>

                            @can('mensajeria.responder')
                                <form id="form-responder-mensajeria" class="mensajeria-form-responder">
                                    <div class="mensajeria-input-pill">
                                        <textarea id="mensajeria-texto-respuesta" class="form-control" rows="1"
                                            placeholder="Escribí tu respuesta..." maxlength="2000" required></textarea>
                                        <button type="submit" class="btn btn-primary btn-sm mensajeria-btn-enviar" title="Enviar">
                                            <i class="fas fa-paper-plane"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted mensajeria-contador-caracteres">0 / 2000</small>
                                </form>
                            @else
                                <div class="mensajeria-solo-lectura">
                                    <i class="fas fa-lock me-1"></i> No tenés permiso para responder mensajes.
                                </div>
                            @endcan
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>

<style>
    /* Vista Mensajería (spec 032) — estilos acotados a esta pantalla. Reutiliza
       los componentes de chat que ya trae el template NexaDash (message-sent/
       message-received/chat-box-area, ver public/css/style.css) en vez de
       reinventarlos; sólo se ajusta el layout propio de la bandeja + panel. */
    .mensajeria-card { min-height: 75vh; }

    .mensajeria-bandeja {
        border-right: 1px solid var(--bs-border-color, #e6e6e6);
        display: flex;
        flex-direction: column;
        max-height: 78vh;
    }

    .mensajeria-bandeja-header {
        padding: 1rem 1rem 0.75rem;
        border-bottom: 1px solid var(--bs-border-color, #e6e6e6);
        flex: 0 0 auto;
    }

    .mensajeria-buscador { margin-top: 0.6rem; }

    .mensajeria-lista-wrapper {
        flex: 1 1 auto;
        overflow-y: auto;
    }

    /* La tabla se estiliza como lista de conversaciones, no como grilla de datos. */
    .mensajeria-tabla { margin-bottom: 0 !important; }
    .mensajeria-tabla > tbody > tr { cursor: pointer; }
    .mensajeria-tabla > tbody > tr > td {
        padding: 0.7rem 1rem;
        border-bottom: 1px solid var(--bs-border-color-translucent, rgba(0, 0, 0, 0.06));
        vertical-align: middle;
    }
    .mensajeria-tabla > tbody > tr:hover > td { background: var(--rgba-primary-1); }
    .mensajeria-tabla > tbody > tr.mensajeria-fila-activa > td { background: var(--rgba-primary-1); }
    #tabla-mensajeria_wrapper .dataTables_info,
    #tabla-mensajeria_wrapper .dataTables_paginate {
        padding: 0.65rem 1rem;
    }
    #tabla-mensajeria_wrapper .row:last-child {
        margin: 0;
        border-top: 1px solid var(--bs-border-color, #e6e6e6);
    }
    #tabla-mensajeria_wrapper .dataTables_filter,
    #tabla-mensajeria_wrapper .dataTables_length { display: none; }

    .mensajeria-fila {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        min-width: 0;
    }
    .mensajeria-avatar {
        flex: 0 0 auto;
        width: 2.25rem;
        height: 2.25rem;
        border-radius: 50%;
        background: var(--rgba-primary-1);
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
    }
    .mensajeria-fila-texto { min-width: 0; flex: 1 1 auto; }
    .mensajeria-fila-nombre {
        font-weight: 600;
        font-size: 0.875rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .mensajeria-fila-preview {
        font-size: 0.78rem;
        color: #8a929b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .mensajeria-fila-meta {
        flex: 0 0 auto;
        text-align: right;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 0.25rem;
    }
    .mensajeria-fila-fecha { font-size: 0.72rem; color: #8a929b; white-space: nowrap; }

    .mensajeria-badge-estado { font-weight: 500; font-size: 0.72rem; }
    .mensajeria-badge-pendiente { background: #fff3cd; color: #8a6a00; }
    .mensajeria-badge-respondida { background: #d7f5ea; color: #0a7a52; }
    .mensajeria-badge-cerrada { background: #eceef1; color: #5c636a; }

    .mensajeria-panel { max-height: 78vh; }

    .mensajeria-vacio {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.75rem;
        flex: 1 1 auto;
        color: #8a929b;
        padding: 2rem;
    }
    .mensajeria-vacio i { font-size: 2.25rem; opacity: 0.5; }

    .mensajeria-conversacion { min-height: 0; }

    .mensajeria-conversacion-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.9rem 1.25rem;
        border-bottom: 1px solid var(--bs-border-color, #e6e6e6);
        flex: 0 0 auto;
    }
    .mensajeria-conversacion-header .mensajeria-avatar { width: 2.5rem; height: 2.5rem; }

    .mensajeria-mensajes {
        flex: 1 1 auto;
        overflow-y: auto;
        padding: 1.25rem;
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
    }

    .mensajeria-burbuja-fila {
        display: flex;
        margin-bottom: 0.75rem;
    }
    .mensajeria-burbuja-fila.es-negocio { justify-content: flex-end; }
    .mensajeria-burbuja-fila.es-comprador { justify-content: flex-start; }
    .mensajeria-burbuja-contenido { max-width: 72%; min-width: 0; }

    /* Burbujas estilo WhatsApp, con estilos propios (no dependen de la clase
       .chat-box-area del template, que exigía un ancestro que esta vista no
       tiene). Negocio: azul marino de marca sólido + texto blanco, alineada a
       la derecha. Comprador: gris claro + texto oscuro, alineada a la izquierda. */
    .mensajeria-burbuja-contenido p {
        margin: 0;
        padding: 0.5rem 0.85rem;
        white-space: pre-wrap;
        word-break: break-word;
        font-size: 0.8125rem;
        line-height: 1.4;
    }
    .mensajeria-burbuja-fila.es-negocio .mensajeria-burbuja-contenido p {
        background: var(--primary);
        color: #fff;
        border-radius: 0.9rem 0.9rem 0.15rem 0.9rem;
    }
    .mensajeria-burbuja-fila.es-comprador .mensajeria-burbuja-contenido p {
        background: #eef0f3;
        color: #1c2430;
        border-radius: 0.9rem 0.9rem 0.9rem 0.15rem;
    }

    .mensajeria-burbuja-hora {
        display: block;
        font-size: 0.7rem;
        color: #8a929b;
        margin-top: 0.2rem;
        padding: 0 0.2rem;
    }
    .mensajeria-burbuja-fila.es-negocio .mensajeria-burbuja-hora { text-align: right; }

    .mensajeria-separador-fecha {
        text-align: center;
        font-size: 0.72rem;
        color: #8a929b;
        margin: 0.5rem 0 0.75rem;
    }

    .mensajeria-form-responder {
        flex: 0 0 auto;
        padding: 0.85rem 1.25rem 1rem;
        border-top: 1px solid var(--bs-border-color, #e6e6e6);
    }
    .mensajeria-input-pill {
        display: flex;
        align-items: flex-end;
        gap: 0.5rem;
        background: #F3F0EC;
        border-radius: 1.25rem;
        padding: 0.35rem 0.4rem 0.35rem 0.9rem;
    }
    .mensajeria-input-pill textarea.form-control {
        border: 0;
        background: transparent;
        resize: none;
        padding: 0.35rem 0;
        max-height: 6rem;
    }
    .mensajeria-input-pill textarea.form-control:focus { box-shadow: none; }
    .mensajeria-btn-enviar {
        flex: 0 0 auto;
        width: 2.25rem;
        height: 2.25rem;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0;
    }
    .mensajeria-contador-caracteres {
        display: block;
        text-align: right;
        margin-top: 0.25rem;
        font-size: 0.7rem;
    }

    .mensajeria-solo-lectura {
        flex: 0 0 auto;
        padding: 0.85rem 1.25rem;
        border-top: 1px solid var(--bs-border-color, #e6e6e6);
        color: #8a929b;
        font-size: 0.8125rem;
    }

    .mensajeria-sugerencia {
        flex: 0 0 auto;
        padding: 0.6rem 1.25rem;
        border-top: 1px solid var(--bs-border-color, #e6e6e6);
        font-size: 0.8125rem;
    }
    .mensajeria-sugerencia-texto {
        background: var(--rgba-primary-1);
        border-radius: 0.6rem;
        padding: 0.5rem 0.75rem;
        color: #1c2430;
    }
    [data-theme-version="dark"] .mensajeria-sugerencia-texto { color: #e7e9ec; }
    [data-theme-version="dark"] .mensajeria-sugerencia { border-color: rgba(255, 255, 255, 0.08); }

    @media (prefers-reduced-motion: no-preference) {
        .mensajeria-burbuja-fila { animation: mensajeria-aparecer 0.15s ease-out; }
    }
    @keyframes mensajeria-aparecer {
        from { opacity: 0; transform: translateY(4px); }
        to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 991.98px) {
        .mensajeria-bandeja, .mensajeria-panel { max-height: none; }
        .mensajeria-bandeja { border-right: 0; border-bottom: 1px solid var(--bs-border-color, #e6e6e6); }
    }

    [data-theme-version="dark"] .mensajeria-bandeja,
    [data-theme-version="dark"] .mensajeria-conversacion-header,
    [data-theme-version="dark"] .mensajeria-form-responder,
    [data-theme-version="dark"] .mensajeria-solo-lectura,
    [data-theme-version="dark"] .mensajeria-bandeja-header,
    [data-theme-version="dark"] #tabla-mensajeria_wrapper .row:last-child,
    [data-theme-version="dark"] .mensajeria-tabla > tbody > tr > td {
        border-color: rgba(255, 255, 255, 0.08);
    }
    [data-theme-version="dark"] .mensajeria-input-pill { background: rgba(255, 255, 255, 0.06); }
    [data-theme-version="dark"] .mensajeria-fila-preview,
    [data-theme-version="dark"] .mensajeria-fila-fecha,
    [data-theme-version="dark"] .mensajeria-vacio,
    [data-theme-version="dark"] .mensajeria-solo-lectura { color: #9aa1ab; }
</style>
@endsection

@section('local-js')
<script>
    window.MensajeriaConfig = {
        rutas: {
            datatable: @json(route('mensajeria.datatable')),
            actualizaciones: @json(route('mensajeria.actualizaciones')),
            show: @json(url('mensajeria')),
            responder: @json(url('mensajeria')),
            sugerencia: @json(url('mensajeria')),
        },
        puedeResponder: @json(auth()->user()->tienePermiso('mensajeria.responder')),
    };
</script>
@vite(['resources/js/mensajeria.js'])
@endsection
