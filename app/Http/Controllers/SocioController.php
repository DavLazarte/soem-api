<?php

namespace App\Http\Controllers;

use App\Models\Cuota;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SocioController extends Controller
{
    /**
     * Dashboard: saldo, profile info, last 10 transactions.
     */
    public function dashboard(Request $request)
    {
        $socio = $request->user()->socio;

        $movimientos = $this->getMovimientos($socio);

        $cuotasPendientes = Cuota::where('estado', 'pendiente')
            ->whereHas('transaccion', function ($q) use ($socio) {
                $q->where('socio_id', $socio->id);
            })
            ->with(['transaccion.prestador:id,nombre', 'periodo:id,nombre'])
            ->orderBy('periodo_id')
            ->get();

        $prestamosVigentes = $socio->prestamos()
            ->where('estado', 'vigente')
            ->with(['cuotasPrestamo.periodo:id,nombre'])
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'saldo_disponible'  => $socio->saldo_disponible,
                'nombre'            => $socio->nombre,
                'apellido'          => $socio->apellido,
                'legajo'            => $socio->legajo,
                'estado'            => $socio->estado,
                'movimientos'       => $movimientos->take(10),
                'cuotas_pendientes' => $cuotasPendientes,
                'prestamos_vigentes'=> $prestamosVigentes,
            ],
        ]);
    }

    private function getMovimientos($socio)
    {
        $movs = collect();

        // ── Acreditaciones (depósitos) ──
        foreach ($socio->acreditaciones as $a) {
            $movs->push([
                'id'     => 'a_'.$a->id,
                'tipo'   => 'acreditacion',
                'titulo' => 'Depósito de saldo',
                'monto'  => $a->monto,
                'signo'  => '+',
                'estado' => 'completado',
                'fecha'  => $a->created_at->toIso8601String(),
            ]);
        }

        // ── Compras de 1 pago (excluir tipo='manual' que son cargas viejas) ──
        foreach ($socio->transacciones()->where('es_cuotas', false)->with('prestador')->get() as $t) {
            $movs->push([
                'id'     => 't_'.$t->id,
                'tipo'   => 'compra',
                'titulo' => 'Compra en ' . ($t->prestador->nombre ?? 'Negocio'),
                'monto'  => $t->monto_total,
                'signo'  => '-',
                'estado' => $t->estado,
                'fecha'  => $t->created_at->toIso8601String(),
                'detalle' => $t->detalle,
            ]);

            if ($t->estado === 'anulada') {
                $movs->push([
                    'id'     => 't_anul_'.$t->id,
                    'tipo'   => 'acreditacion',
                    'titulo' => 'Reintegro por anulación (' . ($t->prestador->nombre ?? 'Negocio') . ')',
                    'monto'  => $t->monto_total,
                    'signo'  => '+',
                    'estado' => 'devuelto',
                    'fecha'  => $t->updated_at->toIso8601String(),
                    'detalle' => $t->motivo_anulacion ? 'Motivo: ' . $t->motivo_anulacion : null,
                ]);
            }
        }

        // ── Cuotas cobradas (o cobradas que luego fueron anuladas) ──
        $cuotas = \App\Models\Cuota::whereHas('transaccion', function($q) use ($socio) {
            $q->where('socio_id', $socio->id);
        })->whereNotNull('cobrada_en')->with('transaccion.prestador', 'periodo')->get();

        foreach ($cuotas as $c) {
            $movs->push([
                'id'     => 'c_'.$c->id,
                'tipo'   => 'cuota',
                'titulo' => 'Cuota ' . $c->nro_cuota . ' - ' . ($c->transaccion->prestador->nombre ?? 'Negocio'),
                'monto'  => $c->monto,
                'signo'  => '-',
                'estado' => $c->estado,
                'fecha'  => \Carbon\Carbon::parse($c->cobrada_en)->toIso8601String(),
            ]);

            if ($c->estado === 'anulada') {
                $movs->push([
                    'id'     => 'c_anul_'.$c->id,
                    'tipo'   => 'acreditacion',
                    'titulo' => 'Reintegro cuota ' . $c->nro_cuota . ' (' . ($c->transaccion->prestador->nombre ?? 'Negocio') . ')',
                    'monto'  => $c->monto,
                    'signo'  => '+',
                    'estado' => 'devuelto',
                    'fecha'  => $c->updated_at->toIso8601String(),
                ]);
            }
        }

        // ── Ajustes por edición de ventas (usando AuditLog) ──
        $transaccionIds = \App\Models\Transaccion::where('socio_id', $socio->id)->pluck('id');
        $ediciones = \App\Models\AuditLog::where('accion', 'edicion_transaccion')
            ->whereIn('modelo_id', $transaccionIds)
            ->get();

        foreach ($ediciones as $edit) {
            $montoAntes = (float)($edit->valores_antes['monto_total'] ?? 0);
            $montoAhora = (float)($edit->valores_despues['monto_total'] ?? 0);
            $diferencia = $montoAhora - $montoAntes;

            if (abs($diferencia) < 0.01) continue;

            $transaccion = \App\Models\Transaccion::with('prestador:id,nombre')->find($edit->modelo_id);
            $nombreNegocio = $transaccion?->prestador?->nombre ?? 'Negocio';

            $movs->push([
                'id'     => 'adj_'.$edit->id,
                'tipo'   => 'ajuste',
                'titulo' => $diferencia < 0
                    ? $nombreNegocio . ' te reintegró dinero'
                    : 'Cargo adicional de ' . $nombreNegocio,
                'monto'  => abs($diferencia),
                'signo'  => $diferencia < 0 ? '+' : '-',
                'estado' => 'aplicado',
                'fecha'  => $edit->created_at->toIso8601String(),
                'detalle' => $transaccion?->motivo_edicion ? 'Motivo: ' . $transaccion->motivo_edicion : null,
            ]);
        }

        // ── Préstamos otorgados ──
        foreach ($socio->prestamos as $p) {
            $movs->push([
                'id'     => 'p_'.$p->id,
                'tipo'   => 'prestamo',
                'titulo' => 'Préstamo otorgado',
                'monto'  => $p->monto_total,
                'signo'  => '+',
                'estado' => $p->estado,
                'fecha'  => $p->created_at->toIso8601String(),
            ]);
        }

        // ── Cuotas préstamo pagadas ──
        $cuotasP = \App\Models\CuotaPrestamo::whereHas('prestamo', function($q) use ($socio) {
            $q->where('socio_id', $socio->id);
        })->where('estado', 'pagada')->get();

        foreach ($cuotasP as $cp) {
            $movs->push([
                'id'     => 'cp_'.$cp->id,
                'tipo'   => 'cuota_prestamo',
                'titulo' => 'Pago cuota préstamo ' . $cp->nro_cuota,
                'monto'  => $cp->monto,
                'signo'  => '-',
                'estado' => 'pagada',
                'fecha'  => ($cp->pagada_en ? \Carbon\Carbon::parse($cp->pagada_en) : $cp->updated_at)->toIso8601String(),
            ]);
        }

        return $movs->sortByDesc('fecha')->values();
    }

    /**
     * Paginated movimientos (transacciones, cuotas, acreditaciones).
     */
    public function movimientos(Request $request)
    {
        $socio = $request->user()->socio;
        $movimientos = $this->getMovimientos($socio);

        $page = $request->get('page', 1);
        $perPage = 15;
        $total = $movimientos->count();
        $items = $movimientos->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'current_page' => (int)$page,
                'data' => $items,
                'last_page' => ceil($total / $perPage) ?: 1,
                'total' => $total,
            ]
        ]);
    }

    /**
     * Paginated acreditaciones with periodo.
     */
    public function acreditaciones(Request $request)
    {
        $socio = $request->user()->socio;

        $acreditaciones = $socio->acreditaciones()
            ->with('periodo:id,nombre')
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $acreditaciones,
        ]);
    }

    /**
     * Update password — the only write action available to socios.
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:6|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'La contraseña actual es incorrecta.',
            ], 422);
        }

        $user->update(['password' => $request->password]);

        return response()->json([
            'success' => true,
            'data'    => ['message' => 'Contraseña actualizada correctamente.'],
        ]);
    }
}
