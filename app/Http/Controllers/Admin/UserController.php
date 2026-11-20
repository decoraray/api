<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use App\Http\Resources\UserShowResource;
use App\Http\Resources\Admin\AdminUserResource;


class UserController extends Controller
{
    public function index()
    {
        $users = User::with('roles')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return AdminUserResource::collection($users);
    }

    public function showByUuid(Request $request)
    {
        $user = User::where('uuid', $request->uuid)->firstOrFail();
        return response()->json(new UserShowResource($user), 200);
    }

    public function show(User $user)
    {
        return response()->json(new UserShowResource($user), 200);
    }

    public function crm(Request $request, User $user, \App\Services\Customers\CustomerProfile $profile)
    {
        return response()->json($profile->for($user, $request->user()));
    }

    public function store(Request $request)
    {
        $user = User::create($request->all());

        return response()->json($user, 201);
    }

    public function update(Request $request, User $user)
    {
        $data = [
            'name' => $request->input('name', $user->name),
            'surname' => $request->input('surname', $user->surname),
            'email' => $request->input('email', $user->email),
            'phone' => $request->input('phone', $user->phone),
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->input('password'));
        }

        $user->update($data);

        return response()->json($user, 200);
    }

    public function destroy(User $user)
    {
        \App\Support\DeleteGuard::customer($user);

        $user->delete();

        return response()->json(null, 204);
    }
}
