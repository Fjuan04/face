<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    //
    public function login(Request $request){
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:5'   
        ]);

        $user = User::where('email',$data['email'])->first();

        if(!$user || !Hash::check($data['password'],$user->password)){
            return response()->json([
                'message' => 'Credenciales incorrectas.'
            ], 401);
        }

        $token = $user->createToken($user["id"]."-token")->plainTextToken;

        return response()->json([
            'message' => 'Login exitoso',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role_id' => $user->role_id,
            ]
        ], 200);
    }
}
