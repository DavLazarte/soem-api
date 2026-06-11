<?php

namespace App\Http\Controllers;

use App\Models\Acreditacion;
use App\Models\AuditLog;
use App\Models\Cuota;
use App\Models\CuotaPrestamo;
use App\Models\Periodo;
use App\Models\Prestador;
use App\Models\Prestamo;
use App\Models\Setting;
use App\Models\Socio;
use App\Models\Transaccion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    // ─── DASHBOARD ───────────────────────────────────────────

    public function dashboard()
    {
        $mesActual = now()->month;
        $anioActual = now()->year;

        $transaccionesMes = Transaccion::whereMonth('created_at', $mesActual)
            ->whereYear('created_at', $anioActual)
            ->where('estado', 'confirmada');

        $ultimasAcreditaciones = Acreditacion::with('socio:id,nombre,apellido,legajo')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $transaccionesUnPagoMes = Transaccion::whereMonth('created_at', $mesActual)
            ->whereYear('created_at', $anioActual)
            ->where('estado', 'confirmada')
            ->where('es_cuotas', false);

        $cuotasCobradasMesMonto = \App\Models\Cuota::whereMonth('cobrada_en', $mesActual)
            ->whereYear('cobrada_en', $anioActual)
            ->where('estado', 'cobrada')
            ->sum('monto');

        $transacciones_mes_monto = (clone $transaccionesUnPagoMes)->sum('monto_total') + $cuotasCobradasMesMonto;

        return response()->json([
            'success' => true,
            'data'    => [
                'total_socios'               => Socio::count(),
                'total_prestadores'          => Prestador::count(),
                'total_saldo_circulacion'    => Socio::sum('saldo_disponible'),
                'transacciones_mes_cantidad' => (clone $transaccionesMes)->count(),
                'transacciones_mes_monto'    => $transacciones_mes_monto,
                'prestamos_vigentes'         => Prestamo::where('estado', 'vigente')->count(),
                'ultimas_acreditaciones'     => $ultimasAcreditaciones,
            ],
        ]);
    }

    // ─── SOCIOS CRUD ─────────────────────────────────────────

    public function indexSocios(Request $request)
    {
        $query = Socio::with('user:id,name,email,estado');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('apellido', 'like', "%{$search}%")
                  ->orWhere('legajo', 'like', "%{$search}%");
            });
        }

        // Filtro por tipo de depósito
        if ($request->filled('deposito')) {
            if ($request->deposito === 'automatico') {
                $query->where('deposito_automatico', true);
            } elseif ($request->deposito === 'revision') {
                $query->where('deposito_automatico', false);
            }
        }

        if ($request->boolean('unpaginated')) {
            $socios = $query->orderBy('apellido')->get();
        } else {
            $socios = $query->orderBy('apellido')->paginate(15);
        }

        return response()->json([
            'success' => true,
            'data'    => $socios,
        ]);
    }

    public function nextLegajo()
    {
        // Buscar el último socio que se cargó en el sistema (el de mayor ID)
        $ultimoSocio = \App\Models\Socio::orderBy('id', 'desc')->first();
        $next = 1;
        if ($ultimoSocio && is_numeric($ultimoSocio->legajo)) {
            $next = (int)$ultimoSocio->legajo + 1;
        }

        return response()->json([
            'success' => true,
            'data'    => ['next' => (string)$next],
        ]);
    }

    public function storeSocio(Request $request)
    {
        $request->validate([
            'nombre'              => 'required|string|max:255',
            'apellido'            => 'required|string|max:255',
            'legajo'              => 'required|string|unique:socios,legajo',
            'celular'             => 'nullable|string|max:50',
            'email'               => 'nullable|email|unique:users,email',
            'password'            => 'nullable|string|min:1',
            'saldo_disponible'    => 'nullable|numeric|min:0',
            'deposito_automatico' => 'nullable|boolean',
        ]);

        $socio = DB::transaction(function () use ($request) {
            $user = User::create([
                'name'     => "{$request->nombre} {$request->apellido}",
                'email'    => $request->email,
                'password' => Hash::make($request->password ?: $request->legajo),
                'role'     => User::ROLE_SOCIO,
                'estado'   => 'activo',
            ]);

            return Socio::create([
                'user_id'             => $user->id,
                'nombre'              => $request->nombre,
                'apellido'            => $request->apellido,
                'legajo'              => $request->legajo,
                'celular'             => $request->celular,
                'estado'              => 'activo',
                'saldo_disponible'    => $request->saldo_disponible ?? 0,
                'deposito_automatico' => $request->deposito_automatico ?? true,
            ]);
        });

        $socio->load('user:id,name,email,estado');

        return response()->json([
            'success' => true,
            'data'    => $socio,
        ], 201);
    }

    public function updateSocio(Request $request, $id)
    {
        $socio = Socio::findOrFail($id);

        $request->validate([
            'nombre'              => 'sometimes|string|max:255',
            'apellido'            => 'sometimes|string|max:255',
            'legajo'              => "sometimes|string|unique:socios,legajo,{$id}",
            'celular'             => 'nullable|string|max:50',
            'estado'              => 'sometimes|in:activo,inactivo',
            'saldo_disponible'    => 'sometimes|numeric',
            'deposito_automatico' => 'sometimes|boolean',
        ]);

        $socio->update($request->only([
            'nombre', 'apellido', 'legajo', 'celular', 'estado',
            'saldo_disponible', 'deposito_automatico',
        ]));

        // Sync name and estado on user
        $user = $socio->user;
        if ($user) {
            if ($request->has('nombre') || $request->has('apellido')) {
                $user->update(['name' => "{$socio->nombre} {$socio->apellido}"]);
            }
            if ($request->has('estado')) {
                $user->update(['estado' => $request->estado]);
            }
        }

        $socio->load('user:id,name,email,estado');

        return response()->json([
            'success' => true,
            'data'    => $socio,
        ]);
    }

    public function destroySocio($id)
    {
        $socio = Socio::findOrFail($id);

        DB::transaction(function () use ($socio) {
            $socio->user->delete(); // soft delete user
            $socio->delete();       // soft delete socio
        });

        return response()->json([
            'success' => true,
            'data'    => ['message' => 'Socio eliminado correctamente.'],
        ]);
    }

    // ─── PRESTADORES CRUD ────────────────────────────────────

    public function indexPrestadores(Request $request)
    {
        $query = Prestador::with('user:id,name,username,email,estado');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where('nombre', 'like', "%{$search}%");
        }

        $prestadores = $query->orderBy('nombre')->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $prestadores,
        ]);
    }

    public function storePrestador(Request $request)
    {
        $request->validate([
            'nombre'    => 'required|string|max:255',
            'direccion' => 'nullable|string|max:500',
            'telefono'  => 'nullable|string|max:50',
            'username'  => 'required|string|unique:users,username',
            'email'     => 'nullable|email|unique:users,email',
            'password'  => 'required|string|min:6',
        ]);

        $prestador = DB::transaction(function () use ($request) {
            $user = User::create([
                'name'     => $request->nombre,
                'username' => $request->username,
                'email'    => $request->email,
                'password' => $request->password,
                'role'     => User::ROLE_PRESTADOR,
                'estado'   => 'activo',
            ]);

            return Prestador::create([
                'user_id'   => $user->id,
                'nombre'    => $request->nombre,
                'direccion' => $request->direccion,
                'telefono'  => $request->telefono,
                'estado'    => 'activo',
            ]);
        });

        $prestador->load('user:id,name,username,email,estado');

        return response()->json([
            'success' => true,
            'data'    => $prestador,
        ], 201);
    }

    public function updatePrestador(Request $request, $id)
    {
        $prestador = Prestador::findOrFail($id);

        $request->validate([
            'nombre'    => 'sometimes|string|max:255',
            'direccion' => 'nullable|string|max:500',
            'telefono'  => 'nullable|string|max:50',
            'estado'    => 'sometimes|in:activo,inactivo',
            'username'  => "sometimes|string|unique:users,username,{$prestador->user_id}",
        ]);

        $prestador->update($request->only(['nombre', 'direccion', 'telefono', 'estado']));

        $user = $prestador->user;
        if ($request->has('nombre')) {
            $user->update(['name' => $request->nombre]);
        }
        if ($request->has('estado')) {
            $user->update(['estado' => $request->estado]);
        }
        if ($request->has('username')) {
            $user->update(['username' => $request->username]);
        }

        $prestador->load('user:id,name,username,email,estado');

        return response()->json([
            'success' => true,
            'data'    => $prestador,
        ]);
    }

    public function destroyPrestador($id)
    {
        $prestador = Prestador::findOrFail($id);

        DB::transaction(function () use ($prestador) {
            $prestador->user->delete();
            $prestador->delete();
        });

        return response()->json([
            'success' => true,
            'data'    => ['message' => 'Prestador eliminado correctamente.'],
        ]);
    }

    // ─── ACREDITACIONES ──────────────────────────────────────

    public function acreditacionMasiva(Request $request)
    {
        $request->validate([
            'monto' => 'required|numeric|min:0.01',
            'socio_ids' => 'required|array|min:1',
            'socio_ids.*' => 'integer|exists:socios,id',
        ]);

        $periodo = Periodo::actual();
        $admin   = $request->user();
        $monto   = $request->monto;
        $ids     = $request->socio_ids;

        $result = DB::transaction(function () use ($monto, $periodo, $admin, $ids) {
            $socios = Socio::whereIn('id', $ids)->get();
            $cuotasCobradas = 0;

            foreach ($socios as $socio) {
                // Guardar saldo anterior antes de acreditar
                $socio->saldo_anterior = $socio->saldo_disponible;

                // Acreditar
                Acreditacion::create([
                    'socio_id'      => $socio->id,
                    'periodo_id'    => $periodo->id,
                    'monto'         => $monto,
                    'estado'        => 'acreditado',
                    'acreditado_por' => $admin->id,
                ]);

                $socio->increment('saldo_disponible', $monto);
                $socio->save(); // save saldo_anterior

                // ── AUTO-COBRO de cuotas de compra pendientes del período ──
                $cuotasPendientes = Cuota::where('estado', 'pendiente')
                    ->whereHas('transaccion', fn($q) => $q->where('socio_id', $socio->id))
                    ->where('periodo_id', $periodo->id)
                    ->with('transaccion.prestador')
                    ->get();

                foreach ($cuotasPendientes as $cuota) {
                    if ($socio->saldo_disponible >= $cuota->monto) {
                        $socio->decrement('saldo_disponible', $cuota->monto);
                        $cuota->update([
                            'estado'    => 'cobrada',
                            'cobrada_en' => now(),
                        ]);
                        $cuotasCobradas++;
                    }
                }

                // ── AUTO-MARCAR cuotas de préstamo del período como pagadas ──
                CuotaPrestamo::where('estado', 'pendiente')
                    ->where('periodo_id', $periodo->id)
                    ->whereHas('prestamo', fn($q) => $q->where('socio_id', $socio->id))
                    ->update([
                        'estado'    => 'pagada',
                        'pagada_en' => now(),
                    ]);

                // Actualizar estado de préstamos si se completaron todas las cuotas
                $prestamosVigentes = Prestamo::where('socio_id', $socio->id)
                    ->where('estado', 'vigente')
                    ->get();

                foreach ($prestamosVigentes as $prestamo) {
                    $prestamo->actualizarEstado();
                }
            }

            return [
                'socios_acreditados' => $socios->count(),
                'cuotas_cobradas'    => $cuotasCobradas,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'socios_acreditados' => $result['socios_acreditados'],
                'cuotas_cobradas'    => $result['cuotas_cobradas'],
                'monto'              => $monto,
                'periodo'            => $periodo->nombre,
            ],
        ]);
    }

    public function acreditaciones(Request $request)
    {
        $acreditaciones = Acreditacion::with(['socio:id,nombre,apellido,legajo', 'periodo:id,nombre'])
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $acreditaciones,
        ]);
    }

    // ─── TRANSACCIONES ───────────────────────────────────────

    public function transacciones(Request $request)
    {
        $query = Transaccion::with([
            'socio:id,nombre,apellido,legajo',
            'prestador:id,nombre',
            'periodo:id,nombre',
            'cuotas',
        ]);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->whereHas('socio', function($qSocio) use ($search) {
                    $qSocio->where('nombre', 'like', "%{$search}%")
                           ->orWhere('apellido', 'like', "%{$search}%")
                           ->orWhere('legajo', 'like', "%{$search}%");
                })->orWhereHas('prestador', function($qPrestador) use ($search) {
                    $qPrestador->where('nombre', 'like', "%{$search}%");
                });
            });
        }

        if ($request->filled('socio_id')) {
            $query->where('socio_id', $request->socio_id);
        }
        if ($request->filled('prestador_id')) {
            $query->where('prestador_id', $request->prestador_id);
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->filled('periodo_id')) {
            $query->where('periodo_id', $request->periodo_id);
        }
        if ($request->filled('fecha_desde')) {
            $query->whereDate('created_at', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('created_at', '<=', $request->fecha_hasta);
        }

        if ($request->boolean('unpaginated')) {
            $transacciones = $query->orderByDesc('created_at')->get();
        } else {
            $transacciones = $query->orderByDesc('created_at')->paginate(15);
        }

        return response()->json([
            'success' => true,
            'data'    => $transacciones,
        ]);
    }

    public function cuotas(Request $request)
    {
        $query = \App\Models\Cuota::with([
            'transaccion.socio:id,nombre,apellido,legajo',
            'transaccion.prestador:id,nombre',
            'periodo:id,nombre'
        ]);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('transaccion', function($q) use ($search) {
                $q->whereHas('socio', function($qSocio) use ($search) {
                    $qSocio->where('nombre', 'like', "%{$search}%")
                           ->orWhere('apellido', 'like', "%{$search}%")
                           ->orWhere('legajo', 'like', "%{$search}%");
                })->orWhereHas('prestador', function($qPrestador) use ($search) {
                    $qPrestador->where('nombre', 'like', "%{$search}%");
                });
            });
        }

        if ($request->filled('prestador_id')) {
            $query->whereHas('transaccion', function($q) use ($request) {
                $q->where('prestador_id', $request->prestador_id);
            });
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->filled('periodo_id')) {
            $query->where('periodo_id', $request->periodo_id);
        }
        if ($request->filled('fecha_desde')) {
            $query->whereDate('cobrada_en', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('cobrada_en', '<=', $request->fecha_hasta);
        }

        if ($request->boolean('unpaginated')) {
            $cuotas = $query->orderByDesc('cobrada_en')->orderByDesc('created_at')->get();
        } else {
            $cuotas = $query->orderByDesc('cobrada_en')->orderByDesc('created_at')->paginate(15);
        }

        return response()->json([
            'success' => true,
            'data'    => $cuotas,
        ]);
    }

    public function anularTransaccion($id)
    {
        $transaccion = Transaccion::findOrFail($id);

        if ($transaccion->estado === 'anulada') {
            return response()->json([
                'success' => false,
                'message' => 'La transacción ya fue anulada.',
            ], 422);
        }

        DB::transaction(function () use ($transaccion) {
            $socio = $transaccion->socio;

            // Restore saldo
            $socio->increment('saldo_disponible', $transaccion->monto_total);

            // Audit log
            AuditLog::registrar(
                'anulacion_transaccion',
                $transaccion,
                ['estado' => $transaccion->estado, 'monto_total' => $transaccion->monto_total],
                ['estado' => 'anulada']
            );

            $transaccion->update([
                'estado'           => 'anulada',
                'anulada_por'      => auth()->id(),
                'motivo_anulacion' => 'Anulada por administrador',
            ]);

            // If installments, cancel pending cuotas
            if ($transaccion->es_cuotas) {
                $transaccion->cuotas()
                    ->where('estado', 'pendiente')
                    ->update(['estado' => 'anulada']);
            }
        });

        $transaccion->load(['socio:id,nombre,apellido,legajo', 'prestador:id,nombre']);

        return response()->json([
            'success' => true,
            'data'    => $transaccion,
        ]);
    }

    // ─── PERIODOS ────────────────────────────────────────────

    public function periodos()
    {
        $periodos = Periodo::orderByDesc('anio')->orderByDesc('mes')->get();

        return response()->json([
            'success' => true,
            'data'    => $periodos,
        ]);
    }

    public function crearPeriodo(Request $request)
    {
        $request->validate([
            'mes'    => 'required|integer|min:1|max:12',
            'anio'   => 'required|integer|min:2020|max:2099',
            'estado' => 'sometimes|in:abierto,cerrado',
        ]);

        $periodo = Periodo::updateOrCreate(
            ['mes' => $request->mes, 'anio' => $request->anio],
            [
                'nombre' => $request->nombre ?? now()->setMonth($request->mes)->setYear($request->anio)->translatedFormat('F Y'),
                'estado' => $request->estado ?? 'abierto',
            ]
        );

        // ── AUTO-MARCAR cuotas de préstamo del nuevo período ──
        CuotaPrestamo::where('estado', 'pendiente')
            ->where('periodo_id', $periodo->id)
            ->update([
                'estado'    => 'pagada',
                'pagada_en' => now(),
            ]);

        // Actualizar estado de préstamos finalizados
        $prestamosAfectados = Prestamo::where('estado', 'vigente')
            ->whereHas('cuotasPrestamo', fn($q) => $q->where('periodo_id', $periodo->id))
            ->get();

        foreach ($prestamosAfectados as $prestamo) {
            $prestamo->actualizarEstado();
        }

        return response()->json([
            'success' => true,
            'data'    => $periodo,
        ], 201);
    }

    // ─── PRÉSTAMOS (FINANCIERA) ─────────────────────────────

    public function indexPrestamos(Request $request)
    {
        $query = Prestamo::with([
            'socio:id,nombre,apellido,legajo',
            'cuotasPrestamo.periodo:id,nombre',
            'aprobador:id,name',
        ]);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('socio', function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('apellido', 'like', "%{$search}%")
                  ->orWhere('legajo', 'like', "%{$search}%");
            });
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        $prestamos = $query->orderByDesc('created_at')->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $prestamos,
        ]);
    }

    public function storePrestamo(Request $request)
    {
        $request->validate([
            'socio_id'        => 'required|integer|exists:socios,id',
            'monto_total'     => 'required|numeric|min:1',
            'cantidad_cuotas' => 'required|integer|in:1,2,3',
            'observaciones'   => 'nullable|string|max:1000',
        ]);

        $montoCuota = round($request->monto_total / $request->cantidad_cuotas, 2);
        $periodoActual = Periodo::actual();

        $prestamo = DB::transaction(function () use ($request, $montoCuota, $periodoActual) {
            $prestamo = Prestamo::create([
                'socio_id'        => $request->socio_id,
                'monto_total'     => $request->monto_total,
                'cantidad_cuotas' => $request->cantidad_cuotas,
                'monto_cuota'     => $montoCuota,
                'cuotas_pagadas'  => 0,
                'estado'          => 'vigente',
                'aprobado_por'    => $request->user()->id,
                'observaciones'   => $request->observaciones,
            ]);

            // Generar cuotas asignadas a periodos consecutivos
            $mes = $periodoActual->mes;
            $anio = $periodoActual->anio;

            for ($i = 1; $i <= $request->cantidad_cuotas; $i++) {
                // Avanzar al siguiente mes para cada cuota
                $mes++;
                if ($mes > 12) {
                    $mes = 1;
                    $anio++;
                }

                $periodo = Periodo::firstOrCreate(
                    ['mes' => $mes, 'anio' => $anio],
                    [
                        'nombre' => now()->setMonth($mes)->setYear($anio)->translatedFormat('F Y'),
                        'estado' => 'abierto',
                    ]
                );

                CuotaPrestamo::create([
                    'prestamo_id' => $prestamo->id,
                    'nro_cuota'   => $i,
                    'monto'       => $montoCuota,
                    'estado'      => 'pendiente',
                    'periodo_id'  => $periodo->id,
                ]);
            }

            return $prestamo;
        });

        $prestamo->load(['socio:id,nombre,apellido,legajo', 'cuotasPrestamo.periodo:id,nombre']);

        return response()->json([
            'success' => true,
            'data'    => $prestamo,
        ], 201);
    }

    public function pagarCuotaPrestamo($cuotaId)
    {
        $cuota = CuotaPrestamo::findOrFail($cuotaId);

        if ($cuota->estado === 'pagada') {
            // Desmarcar: admin quiere revertir
            $cuota->update([
                'estado'    => 'pendiente',
                'pagada_en' => null,
            ]);
        } else {
            // Marcar como pagada
            $cuota->update([
                'estado'    => 'pagada',
                'pagada_en' => now(),
            ]);
        }

        // Actualizar estado del préstamo
        $cuota->prestamo->actualizarEstado();

        $cuota->load('prestamo.socio:id,nombre,apellido,legajo');

        return response()->json([
            'success' => true,
            'data'    => $cuota,
        ]);
    }

    public function cancelarPrestamo($id)
    {
        $prestamo = Prestamo::findOrFail($id);

        if ($prestamo->estado === 'cancelado') {
            return response()->json([
                'success' => false,
                'message' => 'El préstamo ya está cancelado.',
            ], 422);
        }

        DB::transaction(function () use ($prestamo) {
            $prestamo->cuotasPrestamo()
                ->where('estado', 'pendiente')
                ->update(['estado' => 'anulada']);

            $prestamo->update(['estado' => 'cancelado']);
        });

        $prestamo->load(['socio:id,nombre,apellido,legajo', 'cuotasPrestamo']);

        return response()->json([
            'success' => true,
            'data'    => $prestamo,
        ]);
    }

    // ─── AUDIT LOGS ──────────────────────────────────────────

    public function auditLogs(Request $request)
    {
        $logs = AuditLog::with('user:id,name,role')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $logs,
        ]);
    }

    // ─── SETTINGS ────────────────────────────────────────────

    public function getSettings()
    {
        $settings = Setting::all();

        return response()->json([
            'success' => true,
            'data'    => $settings,
        ]);
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'settings'         => 'required|array',
            'settings.*.key'   => 'required|string',
            'settings.*.value' => 'required',
        ]);

        foreach ($request->settings as $setting) {
            Setting::set($setting['key'], $setting['value']);
        }

        return response()->json([
            'success' => true,
            'data'    => Setting::all(),
        ]);
    }
}
