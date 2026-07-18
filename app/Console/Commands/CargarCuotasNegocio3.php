<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Periodo;
use App\Models\Socio;
use App\Models\Transaccion;
use App\Models\Cuota;

class CargarCuotasNegocio3 extends Command
{
    protected $signature = 'soem:cargar-cuotas-negocio3 {prestador_id}';
    protected $description = 'Carga el lote de cuotas escritas a mano - Junio 26 (tercer prestador)';

    public function handle()
    {
        $prestador_id = $this->argument('prestador_id');

        // Columnas: legajo | cantidad_cuotas | monto_por_cuota
        $cargas = [
            ['legajo' => '2323', 'cantidad' => 3, 'monto' => 40667],
            ['legajo' => '4080', 'cantidad' => 3, 'monto' => 68000],
            ['legajo' => '2084', 'cantidad' => 3, 'monto' => 66334],
            ['legajo' => '4066', 'cantidad' => 3, 'monto' => 31667],
            ['legajo' => '3853', 'cantidad' => 3, 'monto' => 37334],
            ['legajo' => '2059', 'cantidad' => 3, 'monto' => 66000],
            ['legajo' => '3614', 'cantidad' => 3, 'monto' => 25000],
            ['legajo' => '2054', 'cantidad' => 3, 'monto' => 91400],
            ['legajo' => '2456', 'cantidad' => 3, 'monto' => 30000],
            ['legajo' => '3703', 'cantidad' => 3, 'monto' => 66000],
            ['legajo' => '3332', 'cantidad' => 3, 'monto' => 65000],
            ['legajo' => '3397', 'cantidad' => 3, 'monto' => 39000],
            ['legajo' => '2147', 'cantidad' => 3, 'monto' => 19000],
            ['legajo' => '2908', 'cantidad' => 3, 'monto' => 59400],
            ['legajo' => '2647', 'cantidad' => 3, 'monto' => 66700],
            ['legajo' => '2603', 'cantidad' => 3, 'monto' => 66700],
            ['legajo' => '2406', 'cantidad' => 3, 'monto' => 33400],
            ['legajo' => '2468', 'cantidad' => 3, 'monto' => 36200],
            ['legajo' => '2867', 'cantidad' => 3, 'monto' => 10400],
            ['legajo' => '1385', 'cantidad' => 3, 'monto' => 55670],
            ['legajo' => '3691', 'cantidad' => 3, 'monto' => 15000],
            ['legajo' => '2574', 'cantidad' => 3, 'monto' => 35000],
            ['legajo' => '2447', 'cantidad' => 3, 'monto' => 38000],
        ];

        $periodoActual = Periodo::where('estado', 'abierto')->first();

        $this->info("Iniciando carga de libreta a mano - Junio 26...");

        foreach ($cargas as $carga) {
            $socio = Socio::where('legajo', $carga['legajo'])->first();
            if (!$socio) {
                $this->error("⚠️ Socio no encontrado: Legajo " . $carga['legajo']);
                continue;
            }

            $transaccion = Transaccion::create([
                'socio_id'     => $socio->id,
                'prestador_id' => $prestador_id,
                'periodo_id'   => $periodoActual->id ?? 1,
                'tipo'         => 'manual',
                'monto_total'  => $carga['monto'] * $carga['cantidad'],
                'estado'       => 'confirmada',
                'es_cuotas'    => true,
            ]);

            for ($i = 1; $i <= $carga['cantidad']; $i++) {
                $fechaCuota = now()->addMonths($i - 1);
                $periodoCuota = Periodo::firstOrCreate(
                    ['mes' => $fechaCuota->month, 'anio' => $fechaCuota->year],
                    ['nombre' => ucfirst($fechaCuota->translatedFormat('F Y')), 'estado' => 'abierto']
                );

                Cuota::create([
                    'transaccion_id' => $transaccion->id,
                    'periodo_id'     => $periodoCuota->id,
                    'nro_cuota'      => $i,
                    'monto'          => $carga['monto'],
                    'estado'         => 'pendiente',
                ]);
            }

            $this->line("✅ Cargadas " . $carga['cantidad'] . " cuotas de $" . number_format($carga['monto'], 0, ',', '.') . " a " . $socio->nombre . " " . $socio->apellido);
        }

        $this->info("🚀 ¡Proceso terminado al 100%!");
    }
}
