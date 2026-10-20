{{-- Progreso de una acción masiva partida en tandas (mismo patrón que la importación). --}}
<div class="modal fade" id="modal-masiva-progreso" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-5">
                <div class="spinner-border text-primary mb-3" role="status" style="width:3rem;height:3rem;"></div>
                <h6 class="fw-bold mb-1" id="masiva-progreso-titulo">Aplicando cambios…</h6>
                <p class="text-muted small mb-3" id="masiva-progreso-detalle">Preparando…</p>
                <div class="progress" style="height:8px;">
                    <div class="progress-bar" id="masiva-progreso-barra" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <p class="text-muted small mt-2 mb-0">No cierres ni recargues esta ventana.</p>
            </div>
        </div>
    </div>
</div>
