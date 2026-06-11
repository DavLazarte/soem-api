<?php

namespace App\Console\Commands;

use App\Models\Prestador;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImportPrestadoresSql extends Command
{
    protected $signature = 'import:prestadores-sql {file=prestadores.sql}';
    protected $description = 'Importa prestadores desde un archivo SQL generando sus usuarios correspondientes';

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

        preg_match_all("/\('(.*?)',\s*'(.*?)',\s*NOW\(\),\s*NOW\(\)\)/", $content, $matches, PREG_SET_ORDER);

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
                $nombre = $match[1];
                $estado = $match[2];

                $cleanName = strtolower(str_replace(' ', '', $nombre));
                $cleanName = Str::ascii($cleanName);
                $username = $cleanName . '.soem';

                $prestador = Prestador::where('nombre', $nombre)->first();
                
                // Si el prestador existe y ya tiene su usuario enlazado, lo omitimos
                if ($prestador && $prestador->user_id) {
                    $omitidos++;
                    $bar->advance();
                    continue;
                }

                // Buscar el usuario o crearlo
                $user = User::where('username', $username)->first();
                if (!$user) {
                    $user = User::create([
                        'name'     => $nombre,
                        'username' => $username,
                        'email'    => null, 
                        'password' => Hash::make('prestador.123'),
                        'role'     => User::ROLE_PRESTADOR,
                        'estado'   => $estado,
                    ]);
                }

                // Si el prestador ya existía (ej. porque se corrió el SQL manualmente), 
                // simplemente le asignamos el ID del usuario recién creado.
                if ($prestador) {
                    $prestador->user_id = $user->id;
                    $prestador->save();
                    $creados++;
                } else {
                    Prestador::create([
                        'user_id'   => $user->id,
                        'nombre'    => $nombre,
                        'direccion' => null,
                        'telefono'  => null,
                        'estado'    => $estado,
                    ]);
                    $creados++;
                }

                $bar->advance();
            }

            DB::commit();
            $bar->finish();
            $this->newLine(2);
            $this->info("¡Importación/Sincronización finalizada con éxito!");
            $this->line("Usuarios/Prestadores creados o arreglados: <fg=green>{$creados}</>");
            $this->line("Prestadores omitidos (ya estaban correctos): <fg=yellow>{$omitidos}</>");

            $this->newLine();
            $this->info("Nota: Los nombres de usuario se generaron quitando espacios. Ej: {$matches[0][1]} -> " . Str::ascii(strtolower(str_replace(' ', '', $matches[0][1]))) . ".soem");
            $this->info("Contraseña por defecto de todos: prestador.123");

        } catch (\Exception $e) {
            DB::rollBack();
            $bar->finish();
            $this->newLine(2);
            $this->error("Ocurrió un error en la importación. Se deshicieron los cambios.");
            $this->error($e->getMessage());
            Log::error('Error importando prestadores: ' . $e->getMessage());
        }
    }
}
