<?php

namespace Database\Seeders;

use App\Models\Acreditacion;
use App\Models\Periodo;
use App\Models\Prestador;
use App\Models\Setting;
use App\Models\Socio;
use App\Models\Transaccion;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // ─── SETTINGS ────────────────────────────────────────────
        Setting::set('saldo_acumulable', 'true');
        Setting::set('permite_negativo', 'false');
        Setting::set('tope_negativo_default', '0');

        // ─── ADMIN ──────────────────────────────────────────────
        User::create([
            'name'     => 'Administrador SOEM',
            'username' => 'admin',
            'password' => Hash::make('password'),
            'role'     => 'admin',
            'estado'   => 'activo',
        ]);

        // ─── PERIODO ACTUAL ─────────────────────────────────────
        $periodo = Periodo::create([
            'nombre' => now()->translatedFormat('F Y'),
            'mes'    => now()->month,
            'anio'   => now()->year,
            'estado' => 'abierto',
        ]);

        // ─── 20 SOCIOS ─────────────────────────────────────────
        $sociosData = [
            ['nombre' => 'Juan',     'apellido' => 'Pérez',     'legajo' => 'S001', 'saldo' => 15000],
            ['nombre' => 'María',    'apellido' => 'García',    'legajo' => 'S002', 'saldo' => 12000],
            ['nombre' => 'Carlos',   'apellido' => 'López',     'legajo' => 'S003', 'saldo' => 18000],
            ['nombre' => 'Ana',      'apellido' => 'Martínez',  'legajo' => 'S004', 'saldo' => 9500],
            ['nombre' => 'Luis',     'apellido' => 'Rodríguez', 'legajo' => 'S005', 'saldo' => 14000],
            ['nombre' => 'Laura',    'apellido' => 'Gómez',     'legajo' => 'S006', 'saldo' => 11000],
            ['nombre' => 'Diego',    'apellido' => 'Fernández', 'legajo' => 'S007', 'saldo' => 16500],
            ['nombre' => 'Sofía',    'apellido' => 'Díaz',      'legajo' => 'S008', 'saldo' => 7000],
            ['nombre' => 'Pablo',    'apellido' => 'Torres',    'legajo' => 'S009', 'saldo' => 20000],
            ['nombre' => 'Lucía',    'apellido' => 'Ramírez',   'legajo' => 'S010', 'saldo' => 13000],
            ['nombre' => 'Martín',   'apellido' => 'Acosta',    'legajo' => 'S011', 'saldo' => 8500],
            ['nombre' => 'Valentina','apellido' => 'Herrera',   'legajo' => 'S012', 'saldo' => 17000],
            ['nombre' => 'Nicolás',  'apellido' => 'Medina',    'legajo' => 'S013', 'saldo' => 10000],
            ['nombre' => 'Camila',   'apellido' => 'Sosa',      'legajo' => 'S014', 'saldo' => 14500],
            ['nombre' => 'Facundo',  'apellido' => 'Castro',    'legajo' => 'S015', 'saldo' => 6000],
            ['nombre' => 'Milagros', 'apellido' => 'Romero',    'legajo' => 'S016', 'saldo' => 19000],
            ['nombre' => 'Tomás',    'apellido' => 'Álvarez',   'legajo' => 'S017', 'saldo' => 11500],
            ['nombre' => 'Julieta',  'apellido' => 'Aguirre',   'legajo' => 'S018', 'saldo' => 15500],
            ['nombre' => 'Agustín',  'apellido' => 'Vega',      'legajo' => 'S019', 'saldo' => 8000],
            ['nombre' => 'Florencia','apellido' => 'Molina',    'legajo' => 'S020', 'saldo' => 12500],
        ];

        $socios = [];
        foreach ($sociosData as $s) {
            $user = User::create([
                'name'     => "{$s['nombre']} {$s['apellido']}",
                'password' => Hash::make('password'),
                'role'     => 'socio',
                'estado'   => 'activo',
            ]);

            $socios[] = Socio::create([
                'user_id'          => $user->id,
                'nombre'           => $s['nombre'],
                'apellido'         => $s['apellido'],
                'legajo'           => $s['legajo'],
                'celular'          => '381' . rand(4000000, 4999999),
                'estado'           => 'activo',
                'saldo_disponible' => $s['saldo'],
                'permite_negativo' => false,
                'acumula_saldo'    => true,
            ]);
        }

        // ─── 10 PRESTADORES ─────────────────────────────────────
        $prestadoresData = [
            ['nombre' => 'Carnicería Don José',     'username' => 'carniceria.donjose',  'direccion' => 'Av. Belgrano 450',     'telefono' => '3814501234'],
            ['nombre' => 'Farmacia Central',         'username' => 'farmacia.central',    'direccion' => 'San Martín 120',       'telefono' => '3814502345'],
            ['nombre' => 'Panadería El Trigo',       'username' => 'panaderia.eltrigo',   'direccion' => 'Crisóstomo Álvarez 890','telefono' => '3814503456'],
            ['nombre' => 'Verdulería La Huerta',     'username' => 'verduleria.lahuerta', 'direccion' => 'Muñecas 355',          'telefono' => '3814504567'],
            ['nombre' => 'Ferretería Tornillo',      'username' => 'ferreteria.tornillo', 'direccion' => 'Laprida 670',          'telefono' => '3814505678'],
            ['nombre' => 'Kiosco La Esquina',        'username' => 'kiosco.laesquina',    'direccion' => '24 de Septiembre 1200','telefono' => '3814506789'],
            ['nombre' => 'Óptica Visual',            'username' => 'optica.visual',       'direccion' => 'Junín 234',            'telefono' => '3814507890'],
            ['nombre' => 'Zapatería El Paso',        'username' => 'zapateria.elpaso',    'direccion' => 'Mendoza 580',          'telefono' => '3814508901'],
            ['nombre' => 'Librería Papel',           'username' => 'libreria.papel',      'direccion' => 'Maipú 412',            'telefono' => '3814509012'],
            ['nombre' => 'Electro Hogar',            'username' => 'electro.hogar',       'direccion' => 'Congreso 750',         'telefono' => '3814500123'],
        ];

        $prestadores = [];
        foreach ($prestadoresData as $p) {
            $user = User::create([
                'name'     => $p['nombre'],
                'username' => $p['username'],
                'password' => Hash::make('password'),
                'role'     => 'prestador',
                'estado'   => 'activo',
            ]);

            $prestadores[] = Prestador::create([
                'user_id'   => $user->id,
                'nombre'    => $p['nombre'],
                'direccion' => $p['direccion'],
                'telefono'  => $p['telefono'],
                'estado'    => 'activo',
            ]);
        }

        // ─── ACREDITACIONES DE EJEMPLO ──────────────────────────
        $admin = User::where('role', 'admin')->first();
        foreach ($socios as $socio) {
            Acreditacion::create([
                'socio_id'       => $socio->id,
                'periodo_id'     => $periodo->id,
                'monto'          => 15000,
                'estado'         => 'acreditado',
                'acreditado_por' => $admin->id,
            ]);
        }

        // ─── TRANSACCIONES DE EJEMPLO ───────────────────────────
        $transaccionesEjemplo = [
            ['socio' => 0, 'prestador' => 0, 'monto' => 3500],
            ['socio' => 0, 'prestador' => 1, 'monto' => 2800],
            ['socio' => 1, 'prestador' => 2, 'monto' => 1200],
            ['socio' => 1, 'prestador' => 3, 'monto' => 4500],
            ['socio' => 2, 'prestador' => 0, 'monto' => 5000],
            ['socio' => 2, 'prestador' => 4, 'monto' => 1800],
            ['socio' => 3, 'prestador' => 1, 'monto' => 3200],
            ['socio' => 4, 'prestador' => 5, 'monto' => 900],
            ['socio' => 5, 'prestador' => 6, 'monto' => 7500],
            ['socio' => 6, 'prestador' => 7, 'monto' => 2100],
            ['socio' => 7, 'prestador' => 8, 'monto' => 1500],
            ['socio' => 8, 'prestador' => 9, 'monto' => 4000],
            ['socio' => 9, 'prestador' => 0, 'monto' => 2200],
            ['socio' => 10, 'prestador' => 1, 'monto' => 6800],
            ['socio' => 11, 'prestador' => 2, 'monto' => 950],
        ];

        foreach ($transaccionesEjemplo as $t) {
            Transaccion::create([
                'socio_id'     => $socios[$t['socio']]->id,
                'prestador_id' => $prestadores[$t['prestador']]->id,
                'periodo_id'   => $periodo->id,
                'tipo'         => 'compra',
                'monto_total'  => $t['monto'],
                'estado'       => 'confirmada',
                'es_cuotas'    => false,
            ]);
        }
    }
}
