<?php

namespace App\Console\Commands;

use App\Models\Socio;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ImportSociosSql extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:socios-sql {file=Socios.sql}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importa socios desde un archivo SQL generando sus usuarios correspondientes';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filename = $this->argument('file');
        $filepath = base_path($filename);

        if (!file_exists($filepath)) {
            $this->error("El archivo {$filepath} no existe.");
            return;
        }

        $this->info("Leyendo archivo: {$filepath}...");
        $content = file_get_contents($filepath);

        // Buscar todos los valores dentro de los paréntesis (...)
        // Formato esperado: ('legajo', 'nombre', 'apellido', 'estado', saldo, permite, acumula, NOW(), NOW())
        preg_match_all("/\('(.*?)',\s*'(.*?)',\s*'(.*?)',\s*'(.*?)',\s*(.*?),\s*(.*?),\s*(.*?),\s*NOW\(\),\s*NOW\(\)\)/", $content, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            $this->error("No se encontraron registros válidos en el archivo o el formato no coincide.");
            return;
        }

        $total = count($matches);
        $this->info("Se encontraron {$total} registros para importar.");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $creados = 0;
        $omitidos = 0;

        DB::beginTransaction();
        try {
            foreach ($matches as $match) {
                $legajo = $match[1];
                $nombre = $match[2];
                $apellido = $match[3];
                $estado = $match[4];
                $saldo_disponible = (float)$match[5];
                $permite_negativo = (int)$match[6];
                $acumula_saldo = (int)$match[7];

                // Verificar si ya existe el socio por legajo
                if (Socio::where('legajo', $legajo)->exists()) {
                    $omitidos++;
                    $bar->advance();
                    continue;
                }

                // 1. Crear el usuario
                // El username será el legajo. Email será null (no se exige).
                // La contraseña será el legajo encriptado.
                $user = User::create([
                    'name'     => "{$nombre} {$apellido}",
                    'username' => $legajo,
                    'email'    => null, // Asumimos null porque no viene en el SQL
                    'password' => Hash::make($legajo),
                    'role'     => User::ROLE_SOCIO,
                    'estado'   => 'activo',
                ]);

                // 2. Crear el socio vinculado
                Socio::create([
                    'user_id'             => $user->id,
                    'legajo'              => $legajo,
                    'nombre'              => $nombre,
                    'apellido'            => $apellido,
                    'celular'             => null,
                    'estado'              => $estado,
                    'saldo_disponible'    => $saldo_disponible,
                    'permite_negativo'    => $permite_negativo,
                    'tope_negativo'       => null,
                    'acumula_saldo'       => $acumula_saldo,
                ]);

                $creados++;
                $bar->advance();
            }

            DB::commit();
            $bar->finish();
            $this->newLine(2);
            $this->info("¡Importación finalizada con éxito!");
            $this->line("Socios creados: <fg=green>{$creados}</>");
            $this->line("Socios omitidos (ya existían): <fg=yellow>{$omitidos}</>");

        } catch (\Exception $e) {
            DB::rollBack();
            $bar->finish();
            $this->newLine(2);
            $this->error("Ocurrió un error en la importación. Se deshicieron los cambios.");
            $this->error($e->getMessage());
            Log::error('Error importando socios: ' . $e->getMessage());
        }
    }
}
