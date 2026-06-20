<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Periodo;
use App\Models\Socio;
use App\Models\Transaccion;
use App\Models\Cuota;

class CargarCuotasNegocio2 extends Command
{
    // Este comando se llama distinto para que tengas los dos por separado
    protected $signature = 'soem:cargar-cuotas-negocio2 {prestador_id}';
    protected $description = 'Carga el lote de cuotas escritas a mano para el segundo prestador';

    public function handle()
    {
        $prestador_id = $this->argument('prestador_id');
        
        $cargas = [
            ['legajo' => '2323', 'cantidad' => 2, 'monto' => 61000],
            ['legajo' => '4080', 'cantidad' => 2, 'monto' => 70000],
            ['legajo' => '2084', 'cantidad' => 2, 'monto' => 68100],
            ['legajo' => '2603', 'cantidad' => 2, 'monto' => 45000],
            ['legajo' => '2647', 'cantidad' => 2, 'monto' => 50000],
            ['legajo' => '2549', 'cantidad' => 2, 'monto' => 60000],
            ['legajo' => '2682', 'cantidad' => 2, 'monto' => 44000],
            ['legajo' => '4066', 'cantidad' => 2, 'monto' => 70000],
            ['legajo' => '3703', 'cantidad' => 2, 'monto' => 85000],
            ['legajo' => '2067', 'cantidad' => 2, 'monto' => 12500],
            ['legajo' => '2147', 'cantidad' => 2, 'monto' => 51500],
            ['legajo' => '2135', 'cantidad' => 2, 'monto' => 79000],
            ['legajo' => '2574', 'cantidad' => 2, 'monto' => 80000],
            ['legajo' => '2724', 'cantidad' => 2, 'monto' => 103500],
            ['legajo' => '2456', 'cantidad' => 2, 'monto' => 72500],
            ['legajo' => '3397', 'cantidad' => 2, 'monto' => 75000],
            ['legajo' => '3853', 'cantidad' => 2, 'monto' => 55000],
            // En la imagen el legajo de Yesica Romano parecía 2903, pero por el listado anterior sé que es 2908
            ['legajo' => '2908', 'cantidad' => 2, 'monto' => 57000],
        ];

        $periodoActual = Periodo::where('estado', 'abierto')->first();

        $this->info("Iniciando carga de libreta a mano...");

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
            
            $this->line("✅ Cargadas " . $carga['cantidad'] . " cuotas de $" . $carga['monto'] . " a " . $socio->nombre . " " . $socio->apellido);
        }
        
        $this->info("🚀 ¡Proceso terminado al 100%!");
    }
}
