<?php

use App\Models\ComprobanteHistoricoArca;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spec 088: tabla de sólo lectura con los 14 comprobantes de venta con CAE real de ARCA que
 * quedaron fuera de la base actual por la migración de agosto. Sin relación a `ventas` (plan,
 * Decisión 2/3) y sin `deleted_at` (plan, Decisión 4) — ver specs/088-comprobantes-historicos-arca/.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobantes_historicos_arca', function (Blueprint $table) {
            $table->id();
            $table->date('fecha_emision');
            $table->char('tipo_comprobante', 1);
            $table->string('punto_venta', 4);
            $table->string('numero');
            $table->string('cae', 14);
            $table->date('cae_vencimiento');
            $table->string('cliente_nombre')->nullable();
            $table->string('cliente_documento_tipo')->nullable();
            $table->string('cliente_documento_numero')->nullable();
            $table->decimal('neto_no_gravado', 14, 2)->default(0);
            $table->decimal('neto_exento', 14, 2)->default(0);
            $table->decimal('neto_gravado', 14, 2)->default(0);
            $table->decimal('iva_2_5', 14, 2)->default(0);
            $table->decimal('iva_5', 14, 2)->default(0);
            $table->decimal('iva_10_5', 14, 2)->default(0);
            $table->decimal('iva_21', 14, 2)->default(0);
            $table->decimal('iva_27', 14, 2)->default(0);
            $table->decimal('perc_iva', 14, 2)->default(0);
            $table->decimal('perc_iibb', 14, 2)->default(0);
            $table->decimal('imp_internos', 14, 2)->default(0);
            $table->decimal('imp_municipales', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->string('origen');
            $table->timestamps();
        });

        $this->insertarRegistros();
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobantes_historicos_arca');
    }

    /**
     * Los 14 valores reales, verificados contra ARCA (data-model.md §2). Vencimiento de CAE = 10
     * días corridos desde la emisión (regla estándar de ARCA para comprobantes electrónicos).
     */
    private function insertarRegistros(): void
    {
        $filas = [
            $this->fila('2026-08-04', 'B', '1', '86316816160690', 'TANIA 1157822317', null, null, 254189.89, 53379.87, 307569.76),
            $this->fila('2026-08-06', 'B', '2', '86327160481043', 'Michela 1171029567', null, null, 2924.80, 614.21, 3539.01),
            $this->fila('2026-08-06', 'B', '3', '86327177560817', 'SILVINA 1159342461', null, null, 128387.16, 26961.30, 155348.46),
            $this->fila('2026-08-07', 'A', '1', '86327351450623', 'ROBERTO 1162714317', 'CUIT', '23247526749', 187674.81, 39411.71, 227086.52),
            $this->fila('2026-08-10', 'B', '4', '86327719127823', 'Valentin 1157505257', null, null, 15482.06, 3251.23, 18733.29),
            $this->fila('2026-08-10', 'A', '2', '86327738189254', 'Freddy 1124594187', 'CUIT', '33504728469', 19268.88, 4046.46, 23315.34),
            $this->fila('2026-08-10', 'A', '3', '86327741754158', 'MARTIN 1125427360', 'CUIT', '30717708446', 164280.47, 34498.90, 198779.37),
            $this->fila('2026-08-10', 'A', '4', '86327769430011', 'ELBA 1145584795', 'CUIT', '30708230827', 58066.96, 12194.06, 70261.02),
            $this->fila('2026-08-11', 'A', '5', '86327942867854', 'Arq Luis 1165325151', 'CUIT', '30594765350', 299253.64, 62843.27, 362096.91),
            $this->fila('2026-08-12', 'B', '5', '86328052554930', 'Marina 1124695933', null, null, 15942.86, 3348.00, 19290.86),
            $this->fila('2026-08-12', 'A', '6', '86328111707744', 'Carlos 1144702571', 'CUIT', '20106618489', 25433.46, 5341.03, 30774.49),
            $this->fila('2026-08-13', 'A', '7', '86338170324738', 'STEPCZUKCARLOSJACOBO', 'CUIT', '30710948581', 34205.22, 7183.10, 41388.32),
            $this->fila('2026-08-13', 'A', '8', '86338170408851', 'STEPCZUKCARLOSJACOBO', 'CUIT', '30710948581', 34205.22, 7183.10, 41388.32),
            $this->fila('2026-08-13', 'B', '6', '86338264884938', null, null, null, 86742.81, 18215.99, 104958.80),
        ];

        foreach ($filas as $fila) {
            $suma = round(
                $fila['neto_no_gravado'] + $fila['neto_exento'] + $fila['neto_gravado']
                + $fila['iva_2_5'] + $fila['iva_5'] + $fila['iva_10_5'] + $fila['iva_21'] + $fila['iva_27']
                + $fila['perc_iva'] + $fila['perc_iibb'] + $fila['imp_internos'] + $fila['imp_municipales'],
                2
            );

            if (abs($suma - $fila['total']) > 0.02) {
                throw new \RuntimeException("Invariante neto+IVA+percepciones=total no cierra para CAE {$fila['cae']}: {$suma} != {$fila['total']}");
            }
        }

        ComprobanteHistoricoArca::insert($filas);
    }

    private function fila(
        string $fecha,
        string $tipo,
        string $numero,
        string $cae,
        ?string $clienteNombre,
        ?string $documentoTipo,
        ?string $documentoNumero,
        float $netoGravado21,
        float $iva21,
        float $total,
    ): array {
        return [
            'fecha_emision' => $fecha,
            'tipo_comprobante' => $tipo,
            'punto_venta' => '0009',
            'numero' => $numero,
            'cae' => $cae,
            'cae_vencimiento' => \Illuminate\Support\Carbon::parse($fecha)->addDays(10)->toDateString(),
            'cliente_nombre' => $clienteNombre,
            'cliente_documento_tipo' => $documentoTipo,
            'cliente_documento_numero' => $documentoNumero,
            'neto_no_gravado' => 0,
            'neto_exento' => 0,
            'neto_gravado' => $netoGravado21,
            'iva_2_5' => 0,
            'iva_5' => 0,
            'iva_10_5' => 0,
            'iva_21' => $iva21,
            'iva_27' => 0,
            'perc_iva' => 0,
            'perc_iibb' => 0,
            'imp_internos' => 0,
            'imp_municipales' => 0,
            'total' => $total,
            'origen' => 'historico_migracion_agosto_2026',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
};
