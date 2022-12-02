<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Cart;
use App\Models\User;
use App\Servises\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;


class AuthController extends Controller
{

    public function login(LoginRequest $request)
    {

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response([
                'message' => ['These credentials do not match our records.']
            ], 404);
        }

        $token = AuthService::createToken($user);

        $cart = Cart::firstOrCreate(['user_id' => $user->id]);
        
        if ($request->getUuid()) {
            if (!empty(Cart::where('uuid', $request->getUuid())->first())) {
                $cart->merge(Cart::where('uuid', $request->getUuid())->first());
            }
        }

        $response = [
            'user' => $user,
            'token' => $token
        ];

        return response($response, 200);

    }

    public function register(RegisterRequest $request) {

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
        ]);

        $token = AuthService::createToken($user);

        $cart = Cart::firstOrCreate(['user_id' => $user->id]);
        if ($request->getUuid()) {
            if (!empty(Cart::where('uuid', $request->getUuid())->first())) {
                $cart->merge(Cart::where('uuid', $request->getUuid())->first());
            }
        }

        $response = [
            'user' => $user,
            'token' => $token
        ];

        return response($response, 201);
    }


}


