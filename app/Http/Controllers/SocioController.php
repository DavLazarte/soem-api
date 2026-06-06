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

        $ultimasTransacciones = $socio->transacciones()
            ->with('prestador:id,nombre')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $cuotasPendientes = Cuota::where('estado', 'pendiente')
            ->whereHas('transaccion', function ($q) use ($socio) {
                $q->where('socio_id', $socio->id);
            })
            ->with(['transaccion.prestador:id,nombre', 'periodo:id,nombre'])
            ->orderBy('periodo_id')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'saldo_disponible'  => $socio->saldo_disponible,
                'nombre'            => $socio->nombre,
                'apellido'          => $socio->apellido,
                'legajo'            => $socio->legajo,
                'estado'            => $socio->estado,
                'transacciones'     => $ultimasTransacciones,
                'cuotas_pendientes' => $cuotasPendientes,
            ],
        ]);
    }

    /**
     * Paginated transactions with prestador relation and cuotas if applicable.
     */
    public function transacciones(Request $request)
    {
        $socio = $request->user()->socio;

        $transacciones = $socio->transacciones()
            ->with(['prestador:id,nombre', 'cuotas'])
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data'    => $transacciones,
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
