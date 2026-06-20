<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Periodo;
use App\Models\Socio;
use App\Models\Transaccion;
use App\Models\Cuota;

class CargarCuotasViejas extends Command
{
    // El comando recibirá el ID del negocio como parámetro
    protected $signature = 'soem:cargar-cuotas {prestador_id}';
    protected $description = 'Carga el lote de cuotas viejas del excel para un prestador específico';

    public function handle()
    {
        $prestador_id = $this->argument('prestador_id');
        
        $cargas = [
            ['legajo' => '1612', 'cantidad' => 1, 'monto' => 39000],
            ['legajo' => '1702', 'cantidad' => 1, 'monto' => 19850],
            ['legajo' => '1702', 'cantidad' => 2, 'monto' => 18900],
            ['legajo' => '1759', 'cantidad' => 2, 'monto' => 92666.67],
            ['legajo' => '1762', 'cantidad' => 1, 'monto' => 17250],
            ['legajo' => '1762', 'cantidad' => 1, 'monto' => 3500],
            ['legajo' => '1768', 'cantidad' => 2, 'monto' => 45650],
            ['legajo' => '2059', 'cantidad' => 1, 'monto' => 8650],
            ['legajo' => '2059', 'cantidad' => 1, 'monto' => 8350],
            ['legajo' => '2259', 'cantidad' => 1, 'monto' => 3750],
            ['legajo' => '2323', 'cantidad' => 1, 'monto' => 16000],
            ['legajo' => '2388', 'cantidad' => 1, 'monto' => 27000],
            ['legajo' => '2539', 'cantidad' => 1, 'monto' => 39250],
            ['legajo' => '2539', 'cantidad' => 1, 'monto' => 11200],
            ['legajo' => '2724', 'cantidad' => 1, 'monto' => 19400],
            ['legajo' => '2724', 'cantidad' => 1, 'monto' => 19750],
            ['legajo' => '2724', 'cantidad' => 1, 'monto' => 3500],
            ['legajo' => '2810', 'cantidad' => 1, 'monto' => 22000],
            ['legajo' => '2864', 'cantidad' => 1, 'monto' => 27950],
            ['legajo' => '2908', 'cantidad' => 2, 'monto' => 59000],
            ['legajo' => '2965', 'cantidad' => 1, 'monto' => 12500],
            ['legajo' => '3352', 'cantidad' => 2, 'monto' => 33000],
            ['legajo' => '3703', 'cantidad' => 1, 'monto' => 21750],
            ['legajo' => '3781', 'cantidad' => 1, 'monto' => 7700],
            ['legajo' => '3799', 'cantidad' => 1, 'monto' => 34150],
            ['legajo' => '3799', 'cantidad' => 1, 'monto' => 13500],
            ['legajo' => '3799', 'cantidad' => 2, 'monto' => 32250],
            ['legajo' => '3799', 'cantidad' => 2, 'monto' => 10000],
            ['legajo' => '3853', 'cantidad' => 2, 'monto' => 57600],
            ['legajo' => '3853', 'cantidad' => 2, 'monto' => 16000],
            ['legajo' => '4066', 'cantidad' => 2, 'monto' => 24500]
        ];

        $periodoActual = Periodo::where('estado', 'abierto')->first();

        $this->info("Iniciando carga masiva de cuotas...");

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
            
            $this->line("✅ Cargadas " . $carga['cantidad'] . " cuotas a " . $socio->nombre . " " . $socio->apellido);
        }
        
        $this->info("🚀 ¡Proceso terminado al 100%!");
    }
}
