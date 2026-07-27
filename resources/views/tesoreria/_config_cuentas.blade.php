{{--
    Modal "Ajustes Cuentas Tesorería" (US2): tabla agrupada por tipo, con
    columnas Nombre / Editar / Visible, más botón "Nueva Cuenta".
--}}
<div class="modal fade" id="modal-config-cuentas" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ajustes Cuentas Tesorería</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="text-end mb-2">
                    <button type="button" class="btn btn-sm btn-success" id="btn-nueva-cuenta">
                        <i class="fas fa-plus me-1"></i> Nueva Cuenta
                    </button>
                </div>
                <div id="config-cuentas-grupos"></div>
            </div>
        </div>
    </div>
</div>
