<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PragmaRX\Google2FA\Google2FA;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'Credenciales incorrectas'
            ], 401);
        }

        $user = $request->user();

        if ($user->two_factor_secret) {
            $tmpToken = encrypt($user->id . '|' . now()->timestamp);

            $user->update(['two_factor_tmp_token' => $tmpToken]);

            return response()->json([
                'two_factor' => true,
                'tmp_token'  => $tmpToken,
                'must_change_password' => $user->must_change_password,
            ]);
        }

        return response()->json([
            'token' => $user->createToken('spa-token')->plainTextToken,
            'user'  => $user,
            'must_change_password' => $user->must_change_password,
        ]);
    }

    public function verifyTwoFactor(Request $request)
    {
        $request->validate([
            'tmp_token' => 'required|string',
            'code'      => 'required|digits:6',
        ]);

        $decrypted = decrypt($request->tmp_token);
        [$userId, $timestamp] = explode('|', $decrypted);

        if (now()->timestamp - $timestamp > 300) {
            return response()->json([
                'message' => 'El token ha expirado, inicia sesión nuevamente'
            ], 401);
        }

        $user = \App\Models\User::findOrFail($userId);

        if ($user->two_factor_tmp_token !== $request->tmp_token) {
            return response()->json([
                'message' => 'Token inválido'
            ], 401);
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($user->two_factor_secret, $request->code);

        if (!$valid) {
            return response()->json([
                'message' => 'Código incorrecto'
            ], 422);
        }

        $user->update(['two_factor_tmp_token' => null]);

        return response()->json([
            'token' => $user->createToken('spa-token')->plainTextToken,
            'user'  => $user,
        ]);
    }

    public function enableTwoFactor(Request $request)
    {
        $user = $request->user();

        if ($user->two_factor_secret) {
            return response()->json([
                'message' => 'El 2FA ya está activado'
            ], 422);
        }

        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $otpUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $qrSvg = base64_encode($writer->writeString($otpUrl));

        $user->update(['two_factor_secret' => $secret]);

        return response()->json([
            'secret' => $secret,
            'qr'     => 'data:image/svg+xml;base64,' . $qrSvg,
        ]);
    }

    public function disableTwoFactor(Request $request)
    {
        $request->validate([
            'code' => 'required|digits:6',
        ]);

        $user = $request->user();

        if (!$user->two_factor_secret) {
            return response()->json([
                'message' => 'El 2FA no está activado'
            ], 422);
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($user->two_factor_secret, $request->code);

        if (!$valid) {
            return response()->json([
                'message' => 'Código incorrecto'
            ], 422);
        }

        $user->update(['two_factor_secret' => null]);

        return response()->json([
            'message' => 'El 2FA ha sido desactivado'
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada']);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();
        $user->password = $request->password;
        $user->must_change_password = false;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Contraseña actualizada correctamente.'
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
