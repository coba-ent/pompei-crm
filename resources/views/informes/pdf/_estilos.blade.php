{{-- Estilos compartidos por los tres PDF de informes (spec 067, US4). dompdf sólo entiende CSS
     básico: nada de flex ni grid, y las tablas se maquetan con `width` por celda. --}}
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 8px; color: #222; margin: 0; }
    h1 { font-size: 14px; margin: 0 0 2px 0; }
    .empresa { font-size: 9px; font-weight: bold; }
    .meta { font-size: 8px; color: #666; margin-bottom: 8px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #2b2b2b; color: #fff; padding: 3px 4px; text-align: left; font-size: 7.5px; }
    td { padding: 2px 4px; border-bottom: 1px solid #e5e5e5; }
    .num { text-align: right; }
    .totales { margin: 8px 0; }
    .totales td { border: 0; padding: 1px 6px 1px 0; }
    .totales .rotulo { color: #666; }
    .totales .valor { font-weight: bold; text-align: right; }
    .grupo td { background: #efefef; font-weight: bold; }
    .subgrupo td { background: #f8f8f8; font-weight: bold; padding-left: 14px; }
    .aviso { margin-top: 10px; padding: 5px; border: 1px solid #d0a000; background: #fff8e0; font-size: 8px; }
</style>
