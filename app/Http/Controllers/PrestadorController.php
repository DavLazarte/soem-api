<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Cuota;
use App\Models\Periodo;
use App\Models\Socio;
use App\Models\Transaccion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PrestadorController extends Controller
{
    /**
     * Dashboard: total cobrado this month, transaction count, last 10 transactions.
     */
    public function dashboard(Request $request)
    {
        $prestador = $request->user()->prestador;

        // Default range: current period or current month
        $periodo = Periodo::actual();
        $defaultDesde = $periodo?->fecha_inicio ?? now()->startOfMonth()->toDateString();
        $defaultHasta = $periodo?->fecha_fin    ?? now()->endOfMonth()->toDateString();

        $desde = $request->query('desde', $defaultDesde) ?: $defaultDesde;
        $hasta = $request->query('hasta', $defaultHasta) ?: $defaultHasta;

        $ultimasTransacciones = $prestador->transacciones()
            ->with('socio:id,nombre,apellido,legajo')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $cuotasPendientes = Cuota::where('estado', 'pendiente')
            ->whereHas('transaccion', function ($q) use ($prestador) {
                $q->where('prestador_id', $prestador->id);
            })
            ->with(['transaccion.socio:id,nombre,apellido,legajo', 'periodo:id,nombre'])
            ->orderBy('periodo_id')
            ->get();

        $cuotasCobradas = Cuota::where('estado', 'cobrada')
            ->whereNotNull('cobrada_en')
            ->whereHas('transaccion', function ($q) use ($prestador) {
                $q->where('prestador_id', $prestador->id);
            })
            ->with(['transaccion.socio:id,nombre,apellido,legajo', 'periodo:id,nombre'])
            ->orderByDesc('cobrada_en')
            ->limit(50)
            ->get();

        // Ventas de 1 pago en el rango
        $ventasUnPago = Transaccion::where('prestador_id', $prestador->id)
            ->whereDate('created_at', '>=', $desde)
            ->whereDate('created_at', '<=', $hasta)
            ->where('estado', 'confirmada')
            ->where('es_cuotas', false);

        $cantidadTransacciones = (clone $ventasUnPago)->count();

        // Cuotas cobradas en el rango
        $cuotasCobradasRangoMonto = Cuota::whereHas('transaccion', function($q) use ($prestador) {
                $q->where('prestador_id', $prestador->id);
            })
            ->whereDate('cobrada_en', '>=', $desde)
            ->whereDate('cobrada_en', '<=', $hasta)
            ->where('estado', 'cobrada')
            ->sum('monto');

        $total_cobrado = (clone $ventasUnPago)->sum('monto_total') + $cuotasCobradasRangoMonto;

        return response()->json([
            'success' => true,
            'data'    => [
                'total_cobrado'          => $total_cobrado,
                'cantidad_transacciones' => $cantidadTransacciones,
                'transacciones'          => $ultimasTransacciones,
                'cuotas_pendientes'      => $cuotasPendientes,
                'cuotas_cobradas'        => $cuotasCobradas,
                'desde'                  => $desde,
                'hasta'                  => $hasta,
            ],
        ]);
    }

    /**
     * Search socio by legajo.
     */
    public function buscarSocio(Request $request)
    {
        $request->validate([
            'legajo' => 'required|string',
        ]);

        $socio = Socio::where('legajo', $request->legajo)->first();

        if (!$socio) {
            return response()->json([
                'success' => false,
                'message' => 'Socio no encontrado.',
            ], 404);
        }

        $prestador = $request->user()->prestador;

        $cuotasPendientes = Cuota::where('estado', 'pendiente')
            ->whereHas('transaccion', function ($q) use ($socio, $prestador) {
                $q->where('socio_id', $socio->id)
                  ->where('prestador_id', $prestador->id);
            })
            ->with(['periodo:id,nombre', 'transaccion:id,monto_total'])
            ->orderBy('periodo_id')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'id'                => $socio->id,
                'nombre_completo'   => $socio->nombreCompleto(),
                'legajo'            => $socio->legajo,
                'saldo_disponible'  => $socio->saldo_disponible,
                'estado'            => $socio->estado,
                'cuotas_pendientes' => $cuotasPendientes,
            ],
        ]);
    }

    /**
     * Register a purchase. Supports cuotas (installments).
     */
    public function registrarCompra(Request $request)
    {
        $request->validate([
            'socio_id'        => 'required|exists:socios,id',
            'monto_total'     => 'required|numeric|min:0.01',
            'es_cuotas'       => 'required|boolean',
            'cantidad_cuotas' => 'required_if:es_cuotas,true|integer|min:1|max:24',
            'detalle'         => 'nullable|string|max:1000',
            'cobro_diferido'  => 'nullable|boolean',
        ]);

        $socio = Socio::findOrFail($request->socio_id);

        // For installments, check balance against first cuota only (unless deferred)
        if ($request->es_cuotas) {
            $montoPrimeraCuota = round($request->monto_total / $request->cantidad_cuotas, 2);
            $montoAVerificar = $request->cobro_diferido ? 0 : $montoPrimeraCuota;
        } else {
            $montoAVerificar = $request->monto_total;
        }

        if (!$socio->tieneSaldoDisponible($montoAVerificar)) {
            return response()->json([
                'success' => false,
                'message' => 'El socio no tiene saldo suficiente.',
            ], 422);
        }

        $prestador = $request->user()->prestador;
        $periodo   = Periodo::actual();

        $transaccion = DB::transaction(function () use ($request, $socio, $prestador, $periodo) {
            $transaccion = Transaccion::create([
                'socio_id'     => $socio->id,
                'prestador_id' => $prestador->id,
                'periodo_id'   => $periodo->id,
                'tipo'         => 'compra',
                'monto_total'  => $request->monto_total,
                'estado'       => 'confirmada',
                'es_cuotas'    => $request->es_cuotas,
                'detalle'      => $request->detalle,
            ]);

            if ($request->es_cuotas) {
                $cantidadCuotas = $request->cantidad_cuotas;
                $montoCuota     = round($request->monto_total / $cantidadCuotas, 2);

                $mesInicio = $request->cobro_diferido ? 1 : 0;

                for ($i = 1; $i <= $cantidadCuotas; $i++) {
                    // Adjust last cuota to avoid rounding differences
                    $monto = ($i === $cantidadCuotas)
                        ? $request->monto_total - ($montoCuota * ($cantidadCuotas - 1))
                        : $montoCuota;

                    $fechaCuota = now()->addMonths($mesInicio + $i - 1);
                    $periodoCuota = Periodo::firstOrCreate(
                        ['mes' => $fechaCuota->month, 'anio' => $fechaCuota->year],
                        ['nombre' => ucfirst($fechaCuota->translatedFormat('F Y')), 'estado' => 'abierto']
                    );

                    Cuota::create([
                        'transaccion_id' => $transaccion->id,
                        'periodo_id'     => $periodoCuota->id,
                        'nro_cuota'      => $i,
                        'monto'          => $monto,
                        'estado'         => ($i === 1 && !$request->cobro_diferido) ? 'cobrada' : 'pendiente',
                        'cobrada_en'     => ($i === 1 && !$request->cobro_diferido) ? now() : null,
                    ]);
                }

                // Only deduct first cuota from saldo if not deferred
                if (!$request->cobro_diferido) {
                    $socio->decrement('saldo_disponible', $montoCuota);
                }
            } else {
                $socio->decrement('saldo_disponible', $request->monto_total);
            }

            return $transaccion;
        });

        $transaccion->load(['socio:id,nombre,apellido,legajo', 'prestador:id,nombre', 'cuotas']);

        return response()->json([
            'success' => true,
            'data'    => $transaccion,
        ], 201);
    }

    /**
     * Edit a transaction amount. Creates an AuditLog entry.
     */
    public function editarTransaccion(Request $request, $id)
    {
        $prestador = $request->user()->prestador;

        $transaccion = Transaccion::where('id', $id)
            ->where('prestador_id', $prestador->id)
            ->firstOrFail();

        if ($transaccion->estado !== 'confirmada') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden editar transacciones confirmadas.',
            ], 422);
        }

        $request->validate([
            'monto_total'    => 'required|numeric|min:0.01',
            'motivo_edicion' => 'required|string|max:1000',
            'fecha_creacion' => 'nullable|date',
        ]);

        $montoAnterior = (float) $transaccion->monto_total;
        $montoNuevo    = (float) $request->monto_total;
        $diferencia    = $montoNuevo - $montoAnterior;

        $fechaAnterior = $transaccion->created_at;
        $fechaNueva = $request->filled('fecha_creacion') ? \Carbon\Carbon::parse($request->fecha_creacion) : $fechaAnterior;
        $cambioFecha = $fechaNueva->format('Y-m-d H:i') !== $fechaAnterior->format('Y-m-d H:i');

        $socio = $transaccion->socio;

        // If increasing, check if socio has balance for the difference
        if ($diferencia > 0 && !$socio->tieneSaldoDisponible($diferencia)) {
            return response()->json([
                'success' => false,
                'message' => 'El socio no tiene saldo suficiente para el ajuste.',
            ], 422);
        }

        DB::transaction(function () use ($transaccion, $socio, $montoAnterior, $montoNuevo, $diferencia, $fechaAnterior, $fechaNueva, $cambioFecha) {
            // Adjust saldo: if monto increased, deduct difference; if decreased, restore difference
            if ($diferencia > 0) {
                $socio->decrement('saldo_disponible', $diferencia);
            } elseif ($diferencia < 0) {
                $socio->increment('saldo_disponible', abs($diferencia));
            }

            $updateData = [
                'monto_total'    => $montoNuevo,
                'editada_por'    => request()->user()->id,
                'motivo_edicion' => request()->motivo_edicion,
            ];

            $valoresAntes = ['monto_total' => $montoAnterior];
            $valoresDespues = ['monto_total' => $montoNuevo];

            if ($cambioFecha) {
                $periodo = \App\Models\Periodo::firstOrCreate(
                    ['mes' => $fechaNueva->month, 'anio' => $fechaNueva->year],
                    ['nombre' => ucfirst($fechaNueva->translatedFormat('F Y')), 'estado' => 'abierto']
                );
                
                $updateData['created_at'] = $fechaNueva;
                $updateData['periodo_id'] = $periodo->id;
                
                $valoresAntes['created_at'] = $fechaAnterior->toDateTimeString();
                $valoresDespues['created_at'] = $fechaNueva->toDateTimeString();
            }

            // Create audit log with before/after
            \App\Models\AuditLog::registrar(
                'edicion_transaccion',
                $transaccion,
                $valoresAntes,
                $valoresDespues
            );

            $transaccion->update($updateData);
        });

        $transaccion->load(['socio:id,nombre,apellido,legajo', 'prestador:id,nombre']);

        return response()->json([
            'success' => true,
            'data'    => $transaccion,
        ]);
    }

    /**
     * Paginated list of this prestador's transactions.
     */
    public function transacciones(Request $request)
    {
        $prestador = $request->user()->prestador;

        $query = $prestador->transacciones()
            ->with(['socio:id,nombre,apellido,legajo', 'cuotas', 'auditLogs' => function($q) {
                $q->where('accion', 'edicion_transaccion')->orderBy('created_at', 'asc');
            }, 'editadaPor:id,name']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('socio', function($qSocio) use ($search) {
                $qSocio->where('nombre', 'like', "%{$search}%")
                       ->orWhere('apellido', 'like', "%{$search}%")
                       ->orWhere('legajo', 'like', "%{$search}%");
            });
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

    /**
     * Anular a transaction (prestador can only anular their own).
     */
    public function anularTransaccion(Request $request, $id)
    {
        $prestador = $request->user()->prestador;

        $transaccion = Transaccion::where('id', $id)
            ->where('prestador_id', $prestador->id)
            ->firstOrFail();

        if ($transaccion->estado === 'anulada') {
            return response()->json([
                'success' => false,
                'message' => 'La transacción ya fue anulada.',
            ], 422);
        }

        $request->validate([
            'motivo_anulacion' => 'required|string|max:500',
            'devolver_saldo'   => 'boolean',
        ]);

        $devolverSaldo = $request->input('devolver_saldo', true);

        DB::transaction(function () use ($transaccion, $request, $devolverSaldo) {
            $socio = $transaccion->socio;

            // Devolver saldo al socio
            if ($devolverSaldo) {
                if ($transaccion->es_cuotas) {
                    // Solo devolver las cuotas ya cobradas
                    $montoCobrado = $transaccion->cuotas()
                        ->whereNotNull('cobrada_en')
                        ->sum('monto');
                    if ($montoCobrado > 0) {
                        $socio->increment('saldo_disponible', $montoCobrado);
                    }
                } else {
                    $socio->increment('saldo_disponible', $transaccion->monto_total);
                }
            }

            if ($transaccion->es_cuotas) {
                // Anular todas las cuotas (pendientes y cobradas) independientemente de si devolvió saldo
                $transaccion->cuotas()
                    ->update(['estado' => 'anulada']);
            }

            $motivoFinal = $request->motivo_anulacion;
            if (!$devolverSaldo) {
                $motivoFinal .= ' (Sin reintegro)';
            }

            $transaccion->update([
                'estado'           => 'anulada',
                'motivo_anulacion' => $motivoFinal,
                'anulada_por'      => $request->user()->id,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Venta anulada correctamente.',
            'data'    => $transaccion->fresh(['socio:id,nombre,apellido,legajo']),
        ]);
    }

    /**
     * Load old pending quotas (cuotas viejas) for a socio.
     * No saldo is deducted — these are future pending charges.
     */
    public function storeCuotasViejas(Request $request)
    {
        $request->validate([
            'socio_id'        => 'required|exists:socios,id',
            'cantidad_cuotas' => 'required|integer|min:1|max:36',
            'monto_cuota'     => 'required|numeric|min:0.01',
        ]);

        $prestador = $request->user()->prestador;
        $periodo   = Periodo::actual();

        $transaccion = DB::transaction(function () use ($request, $prestador, $periodo) {
            $montoTotal = round($request->cantidad_cuotas * $request->monto_cuota, 2);

            $transaccion = Transaccion::create([
                'socio_id'     => $request->socio_id,
                'prestador_id' => $prestador->id,
                'periodo_id'   => $periodo->id,
                'tipo'         => 'manual',
                'monto_total'  => $montoTotal,
                'estado'       => 'confirmada',
                'es_cuotas'    => true,
            ]);

            for ($i = 1; $i <= $request->cantidad_cuotas; $i++) {
                $fechaCuota   = now()->addMonths($i - 1);
                $periodoCuota = Periodo::firstOrCreate(
                    ['mes' => $fechaCuota->month, 'anio' => $fechaCuota->year],
                    ['nombre' => ucfirst($fechaCuota->translatedFormat('F Y')), 'estado' => 'abierto']
                );

                Cuota::create([
                    'transaccion_id' => $transaccion->id,
                    'periodo_id'     => $periodoCuota->id,
                    'nro_cuota'      => $i,
                    'monto'          => $request->monto_cuota,
                    'estado'         => 'pendiente',
                    'cobrada_en'     => null,
                ]);
            }

            return $transaccion;
        });

        $transaccion->load(['socio:id,nombre,apellido,legajo', 'cuotas.periodo:id,nombre']);

        return response()->json([
            'success' => true,
            'data'    => $transaccion,
        ], 201);
    }

    /**
     * List all pending cuotas for this prestador.
     */
    public function cuotasPendientes(Request $request)
    {
        $prestador = $request->user()->prestador;

        $query = Cuota::where('estado', 'pendiente')
            ->whereHas('transaccion', function($q) use ($prestador) {
                $q->where('prestador_id', $prestador->id)
                  ->where('estado', 'confirmada');
            })
            ->with(['transaccion.socio:id,nombre,apellido,legajo,saldo_disponible', 'periodo:id,nombre']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('transaccion.socio', function($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('apellido', 'like', "%{$search}%")
                  ->orWhere('legajo', 'like', "%{$search}%");
            });
        }

        $cuotas = $query->orderBy('periodo_id')->paginate(50);

        return response()->json([
            'success' => true,
            'data'    => $cuotas,
        ]);
    }

    /**
     * List all collected cuotas for this prestador with date filters.
     */
    public function cuotasCobradas(Request $request)
    {
        $prestador = $request->user()->prestador;

        $query = Cuota::where('estado', 'cobrada')
            ->whereHas('transaccion', function($q) use ($prestador) {
                $q->where('prestador_id', $prestador->id);
            })
            ->with(['transaccion.socio:id,nombre,apellido,legajo', 'periodo:id,nombre']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('transaccion.socio', function($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('apellido', 'like', "%{$search}%")
                  ->orWhere('legajo', 'like', "%{$search}%");
            });
        }

        if ($request->filled('fecha_desde')) {
            $query->whereDate('cobrada_en', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('cobrada_en', '<=', $request->fecha_hasta);
        }

        if ($request->boolean('unpaginated')) {
            $cuotas = $query->orderByDesc('cobrada_en')->get();
        } else {
            $cuotas = $query->orderByDesc('cobrada_en')->paginate(20);
        }

        return response()->json([
            'success' => true,
            'data'    => $cuotas,
        ]);
    }

    /**
     * Charge multiple pending cuotas (bulk processing).
     */
    public function cobrarCuotasMasivo(Request $request)
    {
        $prestador = $request->user()->prestador;

        $request->validate([
            'cuota_ids' => 'required|array',
            'cuota_ids.*' => 'integer',
        ]);

        $cuotas = Cuota::whereIn('id', $request->cuota_ids)
            ->where('estado', 'pendiente')
            ->whereHas('transaccion', function($q) use ($prestador) {
                $q->where('prestador_id', $prestador->id)
                  ->where('estado', 'confirmada');
            })
            ->with('transaccion.socio')
            ->get();

        $cobradas = 0;
        $fallidas = 0;

        foreach ($cuotas as $cuota) {
            $socio = $cuota->transaccion->socio;

            if ($socio->tieneSaldoDisponible($cuota->monto)) {
                DB::transaction(function () use ($cuota, $socio) {
                    $socio->decrement('saldo_disponible', $cuota->monto);
                    $cuota->update([
                        'estado'     => 'cobrada',
                        'cobrada_en' => now(),
                    ]);
                });
                $cobradas++;
            } else {
                $fallidas++;
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Proceso finalizado. $cobradas cobradas exitosamente. $fallidas fallaron por falta de saldo.",
            'data'    => [
                'cobradas' => $cobradas,
                'fallidas' => $fallidas,
            ],
        ]);
    }

    /**
     * List cuotas viejas (tipo=manual) for this prestador.
     */
    public function indexCuotasViejas(Request $request)
    {
        $prestador = $request->user()->prestador;

        $query = Transaccion::where('prestador_id', $prestador->id)
            ->where('tipo', 'manual')
            ->with(['socio:id,nombre,apellido,legajo', 'cuotas.periodo:id,nombre']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('socio', function($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                  ->orWhere('apellido', 'like', "%{$search}%")
                  ->orWhere('legajo', 'like', "%{$search}%");
            });
        }

        $transacciones = $query->orderByDesc('created_at')->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $transacciones,
        ]);
    }

    /**
     * Charge a specific pending cuota.
     */
    public function cobrarCuota(Request $request, $id)
    {
        $prestador = $request->user()->prestador;

        $cuota = Cuota::where('id', $id)
            ->where('estado', 'pendiente')
            ->whereHas('transaccion', function($q) use ($prestador) {
                $q->where('prestador_id', $prestador->id)
                  ->where('estado', 'confirmada');
            })
            ->with('transaccion.socio')
            ->firstOrFail();

        $socio = $cuota->transaccion->socio;

        if (!$socio->tieneSaldoDisponible($cuota->monto)) {
            return response()->json([
                'success' => false,
                'message' => 'El socio no tiene saldo suficiente para abonar la cuota.',
            ], 422);
        }

        DB::transaction(function () use ($cuota, $socio) {
            $socio->decrement('saldo_disponible', $cuota->monto);
            $cuota->update([
                'estado'     => 'cobrada',
                'cobrada_en' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Cuota cobrada exitosamente.',
            'data'    => $cuota,
        ]);
    }

    /**
     * Anular una cuota cobrada (o revertirla a pendiente).
     */
    public function anularCuota(Request $request, $id)
    {
        $prestador = $request->user()->prestador;

        $cuota = Cuota::where('id', $id)
            ->where('estado', 'cobrada')
            ->whereHas('transaccion', function($q) use ($prestador) {
                $q->where('prestador_id', $prestador->id);
            })
            ->with('transaccion.socio')
            ->firstOrFail();

        $request->validate([
            'motivo_anulacion' => 'required|string|max:500',
            'nuevo_estado'     => 'required|in:pendiente,anulada',
            'devolver_saldo'   => 'boolean',
        ]);

        $devolverSaldo = $request->input('devolver_saldo', true);
        $nuevoEstado = $request->input('nuevo_estado');
        $motivo = $request->input('motivo_anulacion');

        DB::transaction(function () use ($cuota, $devolverSaldo, $nuevoEstado, $motivo, $request) {
            if ($devolverSaldo) {
                $socio = $cuota->transaccion->socio;
                $socio->increment('saldo_disponible', $cuota->monto);
            }

            // Log de auditoría
            AuditLog::create([
                'user_id'         => $request->user()->id,
                'accion'          => 'anular_cuota',
                'modelo'          => 'Cuota',
                'modelo_id'       => $cuota->id,
                'valores_antes'   => ['estado' => 'cobrada'],
                'valores_despues' => [
                    'estado'           => $nuevoEstado,
                    'motivo_anulacion' => $motivo,
                    'devolver_saldo'   => $devolverSaldo
                ],
                'ip'              => $request->ip(),
            ]);

            $motivoFinal = $motivo;
            if (!$devolverSaldo) {
                $motivoFinal .= ' (Sin reintegro)';
            }

            $cuota->update([
                'estado'           => $nuevoEstado,
                'cobrada_en'       => null,
                'motivo_anulacion' => $motivoFinal,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Cobro de cuota anulado exitosamente.',
            'data'    => $cuota,
        ]);
    }

    /**
     * Update prestador's profile (direccion, telefono) and optionally password.
     */
    public function updatePerfil(Request $request)
    {
        $prestador = $request->user()->prestador;

        $request->validate([
            'direccion'        => 'nullable|string|max:500',
            'telefono'         => 'nullable|string|max:50',
            'current_password' => 'nullable|string',
            'password'         => 'nullable|string|min:6|confirmed',
        ]);

        $prestador->update($request->only(['direccion', 'telefono']));

        if ($request->filled('password')) {
            $user = $request->user();

            if (!$request->filled('current_password') || !Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'La contraseña actual es incorrecta.',
                ], 422);
            }

            $user->update(['password' => $request->password]);
        }

        $prestador->load('user:id,name,username,email,estado');

        return response()->json([
            'success' => true,
            'data'    => $prestador,
            'message' => 'Perfil actualizado correctamente.',
        ]);
    }
}
