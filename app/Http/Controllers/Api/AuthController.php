<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function __construct(protected ReportService $reportService) {}

    public function index()
    {
        return response()->json(User::where('role', 'manager')->get());
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in(['manager', 'admin'])],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => trim((string) $request->email),
            'password' => Hash::make((string) $request->password),
            'role' => $request->role,
            'status' => 'offline',
        ]);

        return response()->json([
            'message' => 'User created',
            'user' => $user,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $email = trim((string) $request->email);
        $password = (string) $request->password;

        $directAdmins = [
            'admin@mfpto.com' => ['name' => 'Admin', 'password' => 'password123'],
            'admin2@mfpto.com' => ['name' => 'Admin 2', 'password' => 'Qwerty123'],
        ];

        $emailKey = strtolower($email);

        if (isset($directAdmins[$emailKey]) && hash_equals($directAdmins[$emailKey]['password'], $password)) {
            $user = User::updateOrCreate(
                ['email' => $emailKey],
                [
                    'name' => $directAdmins[$emailKey]['name'],
                    'password' => Hash::make($password),
                    'role' => 'admin',
                    'status' => 'offline',
                ]
            );
        } else {
            return response()->json(['message' => 'Invalid login or password'], 401);
        }

        $user->update(['status' => 'offline']);
        $user->refresh();

        return response()->json([
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->update(['status' => 'offline']);
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Admin access only'], 403);
        }

        if ($request->user()->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account'], 422);
        }

        if ($user->role !== 'manager') {
            return response()->json(['message' => 'Only managers can be deleted'], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Manager deleted']);
    }
}
