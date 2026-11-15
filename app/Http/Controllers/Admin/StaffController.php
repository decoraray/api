<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminUserResource;

class StaffController extends Controller
{
    protected array $staffRoles = ['SuperAdmin', 'Sales', 'Technic', 'Admin'];

    public function index(Request $request)
    {
        $users = User::role($this->staffRoles)
            ->with('roles')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return AdminUserResource::collection($users);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'surname' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|max:255|unique:users,phone',
            'password' => 'required|string|min:6',
            'role' => 'required|in:SuperAdmin,Sales,Technic,Admin',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'name' => $validated['name'],
            'surname' => $validated['surname'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->assignRole($validated['role']);

        $this->logAccessChange($user, null, $validated['role']);

        return (new AdminUserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'sometimes|nullable|string|max:255',
            'surname' => 'sometimes|nullable|string|max:255',
            'password' => 'sometimes|nullable|string|min:6',
            'role' => 'required|in:SuperAdmin,Sales,Technic,Admin',
        ]);

        $oldRole = $user->roles->whereIn('name', $this->staffRoles)->first()?->name;
        $newRole = $validated['role'];

        if ($oldRole === 'SuperAdmin' && $newRole !== 'SuperAdmin' && $user->is(auth()->user())) {
            return response()->json([
                'message' => 'SuperAdmin cannot remove their own SuperAdmin role.',
            ], 422);
        }

        if ($oldRole !== $newRole && $this->wouldRemoveLastSuperAdmin($user, $newRole)) {
            return response()->json([
                'message' => 'Cannot remove the last SuperAdmin.',
            ], 422);
        }

        $updateData = [];
        if (array_key_exists('name', $validated) && $validated['name'] !== null) {
            $updateData['name'] = $validated['name'];
        }
        if (array_key_exists('surname', $validated) && $validated['surname'] !== null) {
            $updateData['surname'] = $validated['surname'];
        }

        $passwordChanged = false;
        if (array_key_exists('password', $validated) && !empty($validated['password'])) {
            $updateData['password'] = Hash::make($validated['password']);
            $passwordChanged = true;
        }

        if (!empty($updateData)) {
            $user->update($updateData);
        }

        if ($oldRole !== $newRole || $user->roles->whereIn('name', $this->staffRoles)->isEmpty()) {
            $user->syncRoles([$newRole]);
        }

        $this->logAccessChange($user, $oldRole, $newRole, $passwordChanged);

        return new AdminUserResource($user->load('roles'));
    }

    public function removeAccess(Request $request, User $user)
    {
        $oldRole = $user->roles->whereIn('name', $this->staffRoles)->first()?->name;

        if ($oldRole === null) {
            return response()->json([
                'message' => 'User has no staff access to remove.',
            ], 422);
        }

        if ($user->hasRole('SuperAdmin') && $user->is(auth()->user())) {
            return response()->json([
                'message' => 'SuperAdmin cannot remove their own access.',
            ], 422);
        }

        if ($this->wouldRemoveLastSuperAdmin($user, null)) {
            return response()->json([
                'message' => 'Cannot remove the last SuperAdmin.',
            ], 422);
        }

        $user->syncRoles([]);

        $this->logAccessChange($user, $oldRole, null);

        return response()->json(null, 204);
    }

    protected function wouldRemoveLastSuperAdmin(User $user, ?string $newRole): bool
    {
        if (! $user->hasRole('SuperAdmin')) {
            return false;
        }

        if ($newRole === 'SuperAdmin') {
            return false;
        }

        $superAdminCount = User::role('SuperAdmin')->count();
        if ($superAdminCount > 1) {
            return false;
        }

        return true;
    }

    protected function logAccessChange(User $user, ?string $oldRole, ?string $newRole, bool $passwordChanged = false): void
    {
        $event = $this->accessChangeEvent($oldRole, $newRole, $passwordChanged);

        $properties = [
            'old' => ['role' => $oldRole],
            'attributes' => ['role' => $newRole],
        ];

        if ($passwordChanged) {
            $properties['attributes']['password_changed'] = true;
        }

        activity('access')
            ->event($event)
            ->performedOn($user)
            ->causedBy(auth()->user())
            ->withProperties($properties)
            ->log(ucwords(str_replace('_', ' ', $event)));
    }

    protected function accessChangeEvent(?string $oldRole, ?string $newRole, bool $passwordChanged): string
    {
        if ($oldRole === null && $newRole !== null) {
            $event = 'created_staff_access';
        } elseif ($oldRole !== null && $newRole === null) {
            $event = 'removed_staff_access';
        } else {
            $event = 'changed_staff_role';
        }

        if ($passwordChanged) {
            $event .= '_with_password_change';
        }

        return $event;
    }
}
