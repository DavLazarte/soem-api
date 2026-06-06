<?php

namespace App\Http\Controllers;

use App\Models\Socio;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Unified login: socios by legajo, admin/prestadores by username.
     */
    public function login(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string',
            'password'   => 'required|string',
        ]);

        $identifier = $request->identifier;
        $password   = $request->password;
        $user       = null;

        // 1. Try to find a Socio by legajo
        $socio = Socio::where('legajo', $identifier)->first();
        if ($socio) {
            $user = $socio->user;
        }

        // 2. If not found, try User by username
        if (!$user) {
            $user = User::where('username', $identifier)->first();
        }

        // 3. Validate credentials
        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciales inválidas.',
            ], 401);
        }

        // 4. Check user is active
        if ($user->estado !== 'activo') {
            return response()->json([
                'success' => false,
                'message' => 'Su cuenta se encuentra inactiva. Contacte al administrador.',
            ], 401);
        }

        // 5. Create Sanctum token
        $token = $user->createToken('auth-token')->plainTextToken;

        // 6. Load relations based on role
        if ($user->isSocio()) {
            $user->load('socio');
        } elseif ($user->isPrestador()) {
            $user->load('prestador');
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'token' => $token,
                'user'  => $user,
            ],
        ]);
    }

    /**
     * Revoke current token.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'data'    => ['message' => 'Sesión cerrada correctamente.'],
        ]);
    }

    /**
     * Return authenticated user with relations.
     */
    public function me(Request $request)
    {
        $user = $request->user();

        if ($user->isSocio()) {
            $user->load('socio');
        } elseif ($user->isPrestador()) {
            $user->load('prestador');
        }

        return response()->json([
            'success' => true,
            'data'    => $user,
        ]);
    }
}
