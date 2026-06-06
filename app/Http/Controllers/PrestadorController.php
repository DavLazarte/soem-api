<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Cuota;
use App\Models\Periodo;
use App\Models\Socio;
use App\Models\Transaccion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrestadorController extends Controller
{
    /**
     * Dashboard: total cobrado this month, transaction count, last 10 transactions.
     */
    public function dashboard(Request $request)
    {
        $prestador = $request->user()->prestador;

        $transaccionesDelMes = $prestador->transaccionesDelMes();

        $ultimasTransacciones = $prestador->transacciones()
            ->with('socio:id,nombre,apellido,legajo')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_cobrado'       => $transaccionesDelMes->sum('monto_total'),
                'cantidad_transacciones' => $transaccionesDelMes->count(),
                'transacciones'       => $ultimasTransacciones,
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
            'cantidad_cuotas' => 'required_if:es_cuotas,true|integer|min:2|max:24',
        ]);

        $socio = Socio::findOrFail($request->socio_id);

        // For installments, check balance against first cuota only
        if ($request->es_cuotas) {
            $montoPrimeraCuota = round($request->monto_total / $request->cantidad_cuotas, 2);
            $montoAVerificar = $montoPrimeraCuota;
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
            ]);

            if ($request->es_cuotas) {
                $cantidadCuotas = $request->cantidad_cuotas;
                $montoCuota     = round($request->monto_total / $cantidadCuotas, 2);

                for ($i = 1; $i <= $cantidadCuotas; $i++) {
                    // Adjust last cuota to avoid rounding differences
                    $monto = ($i === $cantidadCuotas)
                        ? $request->monto_total - ($montoCuota * ($cantidadCuotas - 1))
                        : $montoCuota;

                    $fechaCuota = now()->addMonths($i - 1);
                    $periodoCuota = Periodo::firstOrCreate(
                        ['mes' => $fechaCuota->month, 'anio' => $fechaCuota->year],
                        ['nombre' => ucfirst($fechaCuota->translatedFormat('F Y')), 'estado' => 'abierto']
                    );

                    Cuota::create([
                        'transaccion_id' => $transaccion->id,
                        'periodo_id'     => $periodoCuota->id,
                        'nro_cuota'      => $i,
                        'monto'          => $monto,
                        'estado'         => ($i === 1) ? 'cobrada' : 'pendiente',
                        'cobrada_en'     => ($i === 1) ? now() : null,
                    ]);
                }

                // Only deduct first cuota from saldo
                $socio->decrement('saldo_disponible', $montoCuota);
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
            'monto_total' => 'required|numeric|min:0.01',
        ]);

        $montoAnterior = (float) $transaccion->monto_total;
        $montoNuevo    = (float) $request->monto_total;
        $diferencia    = $montoNuevo - $montoAnterior;

        $socio = $transaccion->socio;

        // If increasing, check if socio has balance for the difference
        if ($diferencia > 0 && !$socio->tieneSaldoDisponible($diferencia)) {
            return response()->json([
                'success' => false,
                'message' => 'El socio no tiene saldo suficiente para el ajuste.',
            ], 422);
        }

        DB::transaction(function () use ($transaccion, $socio, $montoAnterior, $montoNuevo, $diferencia) {
            // Adjust saldo: if monto increased, deduct difference; if decreased, restore difference
            if ($diferencia > 0) {
                $socio->decrement('saldo_disponible', $diferencia);
            } elseif ($diferencia < 0) {
                $socio->increment('saldo_disponible', abs($diferencia));
            }

            // Create audit log with before/after
            AuditLog::registrar(
                'edicion_transaccion',
                $transaccion,
                ['monto_total' => $montoAnterior],
                ['monto_total' => $montoNuevo]
            );

            $transaccion->update(['monto_total' => $montoNuevo]);
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
            ->with(['socio:id,nombre,apellido,legajo', 'cuotas']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('socio', function($qSocio) use ($search) {
                $qSocio->where('nombre', 'like', "%{$search}%")
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
}
