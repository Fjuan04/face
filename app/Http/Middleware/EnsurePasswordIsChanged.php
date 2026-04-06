<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Si el usuario está autenticado y debe cambiar su contraseña
        if ($user && $user->must_change_password) {
            
            // Permitir solo logout, cambio de contraseña y datos del usuario (me)
            // Nota: El prefijo 'api' suele estar implícito si se declara en routes/api.php
            $allowedRoutes = [
                '*/face/logout',
                '*/face/change-password',
                '*/face/user',
            ];

            // Verificamos si la ruta actual NO está en la lista permitida
            if (!$request->is($allowedRoutes)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acceso denegado: Primero debes realizar el cambio de contraseña obligatorio.',
                    'must_change_password' => true
                ], 403);
            }
        }

        return $next($request);
    }
}
