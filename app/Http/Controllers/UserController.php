<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'tenant', 'tenant.writable']);
    }

    // ─── Page ────────────────────────────────────────────────────────────────

    /**
     * GET /users
     * Accessible only to admins (enforced by Gate in route/here).
     */
    public function index(): Response
    {
        Gate::authorize('manage-users');

        $tenantId = Auth::user()->tenant_id;

        $users = User::where('tenant_id', $tenantId)
            ->orderBy('role')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'created_at']);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => User::ROLES,
        ]);
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    /**
     * POST /users
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-users');

        $tenantId = Auth::user()->tenant_id;

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'role'     => ['required', 'in:' . implode(',', User::ROLES)],
        ]);

        if (User::where('email', $data['email'])->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь с таким email уже существует',
            ], 422);
        }

        $user = User::create([
            'tenant_id' => $tenantId,
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'role'      => $data['role'],
        ]);

        return response()->json([
            'success' => true,
            'user'    => $user->only(['id', 'name', 'email', 'role', 'created_at']),
        ]);
    }

    // ─── Update ───────────────────────────────────────────────────────────────

    /**
     * PUT /users/{user}
     * Allows changing name, role, and/or password.
     * Admin cannot change their own role.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        Gate::authorize('manage-users');

        $this->assertSameTenant($user);

        $data = $request->validate([
            'name'     => ['sometimes', 'string', 'max:255'],
            'role'     => ['sometimes', 'in:' . implode(',', User::ROLES)],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:100'],
        ]);

        // Prevent self-role downgrade
        if (isset($data['role']) && $user->id === Auth::id() && $data['role'] !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Вы не можете изменить свою роль',
            ], 422);
        }

        if (isset($data['name'])) {
            $user->name = $data['name'];
        }

        if (isset($data['role'])) {
            $user->role = $data['role'];
        }

        if (!empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        return response()->json([
            'success' => true,
            'user'    => $user->only(['id', 'name', 'email', 'role', 'created_at']),
        ]);
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    /**
     * DELETE /users/{user}
     */
    public function destroy(User $user): JsonResponse
    {
        Gate::authorize('manage-users');

        $this->assertSameTenant($user);

        if ($user->id === Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Нельзя удалить самого себя',
            ], 422);
        }

        $user->delete();

        return response()->json(['success' => true]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function assertSameTenant(User $user): void
    {
        abort_if($user->tenant_id !== Auth::user()->tenant_id, 403);
    }
}
