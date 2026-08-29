@extends('layouts.default')

@section('content')
<div class="content-body">
    <div class="container-fluid">

        <div class="row align-items-center mb-4">
            <div class="col-sm-12">
                <h4 class="mb-0 text-primary fw-bold">Historial de Importaciones — Productos & Servicios</h4>
                <p class="text-muted mb-0">Corridas confirmadas del asistente de Importar Datos. Podés deshacer una corrida dentro de las 48 horas de haberla confirmado.</p>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="tabla-historial-importacion" class="table table-striped w-100">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Usuario</th>
                                <th>Archivo</th>
                                <th>Copia</th>
                                <th>Creadas</th>
                                <th>Actualizadas</th>
                                <th>Fallidas</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="modal fade" id="modal-confirmar-deshacer-historial" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Deshacer import</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                ¿Confirmás que querés deshacer esta importación? Los productos creados quedarán inactivos y los actualizados volverán a sus valores anteriores. Esta acción no se puede repetir.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" id="btn-confirmar-deshacer-historial">Sí, deshacer</button>
            </div>
        </div>
    </div>
</div>
@include('importacion._modal_informe_cambios')
@endsection

@section('local-js')
<script>
window.jQuery(function ($) {
    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });

    var corridaSeleccionada = null;

    var tabla = $('#tabla-historial-importacion').DataTable({
        processing: true,
        serverSide: true,
        ajax: '{{ route('importacion.historial.datos', 'productos') }}',
        order: [],
        columns: [
            { data: 'confirmado_en' },
            { data: 'usuario' },
            { data: 'archivo_original' },
            {
                // Spec 093 (FR-015): tres estados, no un booleano. "No esta porque vencio" y
                // "no esta porque nunca se guardo" le dicen cosas distintas a quien audita.
                data: 'archivo',
                orderable: false,
                render: function (archivo, tipo, row) {
                    if (archivo.estado === 'disponible') {
                        return '<a href="{{ url('importar-datos/productos/historial') }}/' + row.id + '/archivo" ' +
                            'class="btn btn-sm btn-outline-primary" title="Descargar el archivo original">' +
                            '<i class="fas fa-download"></i> Descargar</a>';
                    }
                    if (archivo.estado === 'vencido') {
                        return '<span class="badge bg-warning" title="Se elimino por antiguedad">Vencido</span>';
                    }
                    return '<span class="badge bg-light text-muted" title="De esta importacion nunca se guardo una copia">Sin copia</span>';
                },
            },
            { data: 'filas_creadas' },
            { data: 'filas_actualizadas' },
            { data: 'filas_fallidas' },
            {
                data: 'estado',
                render: function (estado) {
                    var badges = { vigente: 'success', deshecho: 'secondary', vencido: 'warning' };
                    var etiquetas = { vigente: 'Vigente', deshecho: 'Deshecho', vencido: 'Vencido' };
                    return '<span class="badge bg-' + (badges[estado] || 'secondary') + '">' + (etiquetas[estado] || estado) + '</span>';
                },
            },
            {
                data: null,
                orderable: false,
                render: function (row) {
                    var acciones = [];

                    // FR-007: sin filas de snapshot no hay informe, y el boton lo dice — no se
                    // abre un informe vacio que se leeria como "no cambio nada".
                    if (row.informe_disponible) {
                        acciones.push('<button type="button" class="btn btn-sm btn-outline-primary btn-informe-fila" ' +
                            'data-corrida="' + row.id + '" title="Ver que cambio desde esta importacion">' +
                            '<i class="fas fa-list-check"></i> Ver cambios</button>');
                    } else {
                        acciones.push('<button type="button" class="btn btn-sm btn-outline-secondary" disabled ' +
                            'title="Esta importacion es anterior al registro de detalle: no hay informacion de que cambio">' +
                            '<i class="fas fa-list-check"></i> Sin detalle</button>');
                    }

                    if (row.puede_deshacer) {
                        acciones.push('<button type="button" class="btn btn-sm btn-outline-danger btn-deshacer-fila" data-corrida="' + row.id + '"><i class="fas fa-undo"></i> Deshacer</button>');
                    }

                    return '<div class="d-flex gap-1">' + acciones.join('') + '</div>';
                },
            },
        ],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-AR.json' },
    });

    // ------------------------------------------------------------------------------------
    // Spec 093, US1 — informe de qué cambió. Modal + AJAX, sin recargar (FR-023).
    // ------------------------------------------------------------------------------------

    function escapar(valor) {
        return $('<div>').text(valor === null || valor === undefined ? '—' : valor).html();
    }

    function numero(valor) {
        return (valor === null || valor === undefined) ? '—' : Number(valor).toLocaleString('es-AR');
    }

    function tarjeta(etiqueta, valor, color) {
        return '<div class="col-6 col-md-3"><div class="card border-' + color + ' h-100"><div class="card-body py-3">' +
            '<div class="fs-4 fw-bold text-' + color + '">' + numero(valor) + '</div>' +
            '<div class="text-muted small">' + etiqueta + '</div>' +
            '</div></div></div>';
    }

    function pintarInforme(datos) {
        var $contenido = $('#informe-cambios-contenido');
        var $sinDetalle = $('#informe-cambios-sin-detalle');

        // FR-007: "sin detalle disponible" no es "sin cambios".
        if (!datos.informe_disponible) {
            $contenido.addClass('d-none');
            $sinDetalle.removeClass('d-none').text(datos.motivo);
            $('#informe-cambios-subtitulo').text('');
            return;
        }

        $sinDetalle.addClass('d-none');
        $contenido.removeClass('d-none');

        $('#informe-cambios-subtitulo').text(
            datos.corrida.archivo_original + ' — ' + datos.corrida.filas_con_detalle + ' filas con detalle'
        );

        // La advertencia viene del backend, no está escrita en el HTML (FR-005).
        $('#informe-cambios-advertencia').text(datos.advertencia_metodo);

        // FR-009: una corrida deshecha se señala.
        var $deshecha = $('#informe-cambios-deshecha');
        if (datos.corrida.deshecha_en) {
            $deshecha.removeClass('d-none').text(
                'Esta importación fue deshecha el ' + new Date(datos.corrida.deshecha_en).toLocaleString('es-AR') + '.'
            );
        } else {
            $deshecha.addClass('d-none');
        }

        $('#informe-cambios-resumen').html(
            tarjeta('Con algún cambio', datos.resumen.productos_con_algun_cambio, 'primary') +
            tarjeta('Sin cambios', datos.resumen.productos_sin_cambios, 'secondary') +
            tarjeta('Con actividad posterior', datos.resumen.con_actividad_posterior, 'warning') +
            tarjeta('Productos eliminados', datos.resumen.productos_eliminados, 'danger')
        );

        $('#informe-cambios-campos').html(
            datos.campos.length
                ? datos.campos.map(function (c) {
                    var ejemplo = c.ejemplo
                        ? escapar(c.ejemplo.codigo) + ': <s class="text-muted">' + escapar(c.ejemplo.antes) + '</s> → <strong>' + escapar(c.ejemplo.ahora) + '</strong>'
                        : '—';
                    return '<tr><td>' + escapar(c.etiqueta) + '</td><td class="text-end">' + numero(c.productos) + '</td><td>' + ejemplo + '</td></tr>';
                }).join('')
                : '<tr><td colspan="3" class="text-muted">Ningún campo cambió.</td></tr>'
        );

        $('#informe-cambios-precios').html(
            datos.precios.length
                ? datos.precios.map(function (p) {
                    var ejemplo = p.ejemplo
                        ? escapar(p.ejemplo.codigo) + ': <s class="text-muted">' + numero(p.ejemplo.antes) + '</s> → <strong>' + numero(p.ejemplo.ahora) + '</strong>'
                        : '—';
                    var variacion = (p.ejemplo && p.ejemplo.variacion_pct !== null && p.ejemplo.variacion_pct !== undefined)
                        ? '<span class="text-' + (p.ejemplo.variacion_pct >= 0 ? 'success' : 'danger') + '">' + p.ejemplo.variacion_pct + '%</span>'
                        : '—';
                    return '<tr><td>' + escapar(p.lista) + '</td><td class="text-end">' + numero(p.productos) + '</td><td>' + ejemplo + '</td><td class="text-end">' + variacion + '</td></tr>';
                }).join('')
                : '<tr><td colspan="4" class="text-muted">Ningún precio cambió.</td></tr>'
        );

        $('#informe-cambios-stock').html(
            datos.stock.length
                ? datos.stock.map(function (s) {
                    var marcas = '';
                    // FR-006 / FR-008: la diferencia puede no ser atribuible a la importación.
                    if (s.actividad_posterior) {
                        marcas += ' <span class="badge bg-warning" title="Tuvo ventas, compras o movimientos de stock después de la importación">actividad posterior</span>';
                    }
                    if (s.producto_eliminado) {
                        marcas += ' <span class="badge bg-danger" title="El producto se eliminó después de la importación">eliminado</span>';
                    }
                    var color = s.diferencia < 0 ? 'danger' : 'success';
                    return '<tr>' +
                        '<td>' + escapar(s.codigo) + '</td>' +
                        '<td>' + escapar(s.nombre) + marcas + '</td>' +
                        '<td>' + escapar(s.deposito) + '</td>' +
                        '<td class="text-end">' + numero(s.antes) + '</td>' +
                        '<td class="text-end">' + numero(s.ahora) + '</td>' +
                        '<td class="text-end fw-bold text-' + color + '">' + (s.diferencia > 0 ? '+' : '') + numero(s.diferencia) + '</td>' +
                        '</tr>';
                }).join('')
                : '<tr><td colspan="6" class="text-muted">Ningún stock cambió.</td></tr>'
        );
    }

    $('#tabla-historial-importacion tbody').on('click', '.btn-informe-fila', function () {
        var corrida = $(this).data('corrida');
        var modal = new bootstrap.Modal(document.getElementById('modal-informe-cambios'));

        $('#informe-cambios-cargando').removeClass('d-none');
        $('#informe-cambios-contenido').addClass('d-none');
        $('#informe-cambios-sin-detalle').addClass('d-none');
        modal.show();

        $.getJSON('{{ url('importar-datos/productos/historial') }}/' + corrida + '/informe')
            .done(pintarInforme)
            .fail(function (xhr) {
                modal.hide();
                toastr.error(xhr.responseJSON?.error || 'No se pudo armar el informe de cambios.');
            })
            .always(function () {
                $('#informe-cambios-cargando').addClass('d-none');
            });
    });

    $('#tabla-historial-importacion tbody').on('click', '.btn-deshacer-fila', function () {
        corridaSeleccionada = $(this).data('corrida');
        var modal = new bootstrap.Modal(document.getElementById('modal-confirmar-deshacer-historial'));
        modal.show();
    });

    $('#btn-confirmar-deshacer-historial').on('click', function () {
        if (!corridaSeleccionada) { return; }
        var $btn = $(this).prop('disabled', true);

        $.post('{{ url('importar-datos/productos/historial') }}/' + corridaSeleccionada + '/deshacer')
            .done(function (resp) {
                if (resp.no_revertidas && resp.no_revertidas.length) {
                    toastr.warning(resp.mensaje);
                } else {
                    toastr.success(resp.mensaje);
                }
                tabla.ajax.reload(null, false);
            })
            .fail(function (xhr) {
                toastr.error(xhr.responseJSON?.error || 'No se pudo deshacer la importación.');
            })
            .always(function () {
                $btn.prop('disabled', false);
                bootstrap.Modal.getInstance(document.getElementById('modal-confirmar-deshacer-historial')).hide();
            });
    });
});
</script>
@endsection
