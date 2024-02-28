<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\Auth\AuthService;
use App\Http\Requests\LoginRequest;
use App\Services\Sms\Drivers\SmsIr;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\SmsVerifyRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\SmsRegisterRequest;
use App\Http\Resources\UserShowResource;

class AuthController extends Controller
{
    public function emailLogin(LoginRequest $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response([
                'message' => ['These credentials do not match our records.']
            ], 404);
        }

        return $this->login($user, $request->getUuid());
    }

    public function smsLogin(Request $request)
    {
        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return response([
                'message' => ['These credentials do not match our records.']
            ], 404);
        }

        $user->generateOneTimePassword();

        SmsIr::verify($user->phone, $user->one_time_password);

        $response = [
            'message' => 'Check your sms',
        ];

        return response($response, 200);
    }

    private function login($user, $uuid = null)
    {
        $token = AuthService::createToken($user);

        $cart = Cart::firstOrCreate(['user_id' => $user->id]);

        if ($uuid) {
            if (!empty(Cart::where('uuid', $uuid)->first())) {
                $cart->merge(Cart::where('uuid', $uuid)->first());
            }
        }

        $response = [
            'user' =>  new UserShowResource($user),
            'cart_uuid' => $cart->uuid,
            'token' => $token
        ];

        return response($response, 200);
    }

    public function smsVerify(SmsVerifyRequest $request)
    {
        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return response([
                'message' => ['These credentials do not match our records.']
            ], 404);
        }

        $passed = $user->verifyOneTimePassword($request->password);
        if (!$passed) {
            return response([
                'message' => ['Invalid one time password.']
            ], 404);
        }

        return $this->login($user, $request->getUuid());
    }

    public function register(RegisterRequest $request)
    {

        $user = User::create([
            'uuid' => uniqid(),
            'name' => $request->name,
            'surname' => $request->surname,
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

        $user = User::where('email', $request->email)->first();

        $response = [
            'user' => new UserShowResource($user),
            'token' => $token
        ];

        return response($response, 201);
    }

    public function smsRegister(SmsRegisterRequest $request)
    {
        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            $user = User::create([
                'uuid' => uniqid(),
                'name' => $request->name,
                'surname' => $request->surname,
                'phone' => $request->phone,
                'password' => Hash::make($request->password),
            ]);
        }

        if ($user->phone_verified_at != null) {
            return response([
                'message' => ['This phone number is already registered.']
            ], 404);
        }

        // if ($user->phone == '9363468923') {
        //     $user->one_time_password = '000000';
        //     $user->one_time_password_created_at = now();
        //     return response([
        //         'message' => 'Check your sms',
        //     ], 200);
        // }

        $otpGenerated = $user->generateOneTimePassword();
        if (!$otpGenerated) {
            return response([
                'message' => ['Failed to generate one time password.']
            ], 500);
        }

        SmsIr::verify($user->phone, $user->one_time_password);

        $response = [
            'message' => 'Check your sms',
        ];

        return response($response, 200);
    }

    public function forgotPassword(Request $request)
    {
        $user = User::where('email', $request->email)->first();

        $user->sendPasswordResetNotification($user->createToken('password-reset')->plainTextToken);

        $response = [
            'message' => 'Check your email',
        ];
        return response($response, 200);
    }

    public function update(UpdateUserRequest $request)
    {
        $user = User::where('id', $request->user()->id)->first();

        // filter nulls from request
        $data = array_filter($request->all());

        $user->update($data);

        $response = [
            'user' => $user,
        ];

        return response($response, 200);
    }

    public function user(Request $request)
    {
        $user = User::where('id', $request->user()->id)->first();

        return response()->json(new UserShowResource($user), 200);
    }

    public function ChangePassword(Request $request)
    {
        // validate request
        $request->validate([
            'old_password' => 'required',
            'new_password' => 'required|min:6',
        ]);

        $user = User::where('id', $request->user()->id)->first();

        if (!Hash::check($request->old_password, $user->password)) {
            return response([
                'message' => ['These credentials do not match our records.']
            ], 404);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        $response = [
            'message' => 'Password changed successfully',
        ];

        return response($response, 200);
    }
}
