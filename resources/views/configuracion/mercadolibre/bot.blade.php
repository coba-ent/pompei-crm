@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-12">
                <h4 class="mb-0 text-primary fw-bold">Bot de Mercado Libre</h4>
                <p class="text-muted mb-0">
                    Configurá el tono/instrucciones que sigue la IA al redactar sugerencias de respuesta
                    para la bandeja de Mensajería.
                </p>
            </div>
        </div>

        <div class="alert alert-info small">
            La activación/desactivación del bot se maneja desde
            <a href="{{ route('configuracion.funciones.index') }}">Funciones Avanzadas</a> — acá sólo se
            configura el tono. Con el bot apagado, igual se puede pedir una sugerencia bajo demanda desde
            la Mensajería.
        </div>

        <div class="card">
            <div class="card-header">
                <h6 class="mb-0 fw-bold">Tono / instrucciones</h6>
            </div>
            <div class="card-body">
                <form id="form-bot-mercadolibre">
                    <div class="mb-3">
                        <label for="instrucciones-tono" class="form-label">Instrucciones para la IA</label>
                        <textarea id="instrucciones-tono" name="instrucciones_tono" class="form-control" rows="6"
                            maxlength="2000" placeholder="Ej.: Tono cordial pero directo, tratar de usted al comprador, evitar emojis...">{{ $configuracion->instrucciones_tono }}</textarea>
                        <small class="text-muted">Si se deja vacío, el bot usa un tono neutro por defecto.</small>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Guardar configuración
                    </button>
                </form>
            </div>
        </div>

    </div>
</div>
@endsection

@section('local-js')
<script>
    window.BotMercadoLibreConfig = {
        rutas: {
            guardar: @json(route('configuracion.mercadolibre.bot.guardar')),
        },
    };
</script>
<script>
    (function () {
        'use strict';
        const $ = window.jQuery;
        if (!$) { return; }

        const CSRF = $('meta[name="csrf-token"]').attr('content');
        $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': CSRF } });

        if (window.toastr) {
            window.toastr.options = {
                closeButton: true, progressBar: true, positionClass: 'toast-top-right',
                preventDuplicates: true, newestOnTop: true, timeOut: 4000, extendedTimeOut: 1500,
            };
        }
        function toast(tipo, mensaje) {
            if (window.toastr && window.toastr[tipo]) { window.toastr[tipo](mensaje); }
        }

        $('#form-bot-mercadolibre').on('submit', function (e) {
            e.preventDefault();
            const $boton = $(this).find('button[type="submit"]');
            $boton.prop('disabled', true);

            $.ajax({
                url: window.BotMercadoLibreConfig.rutas.guardar,
                method: 'PUT',
                data: { instrucciones_tono: $('#instrucciones-tono').val() },
            }).done(function (resp) {
                toast('success', resp.mensaje || 'Configuración guardada.');
            }).fail(function (xhr) {
                const mensaje = (xhr.responseJSON && xhr.responseJSON.message) || 'No se pudo guardar la configuración.';
                toast('error', mensaje);
            }).always(function () {
                $boton.prop('disabled', false);
            });
        });
    })();
</script>
@endsection
