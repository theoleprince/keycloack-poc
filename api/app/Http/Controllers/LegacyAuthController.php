<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Authentification historique (Sanctum). Conservée pendant la migration,
 * à retirer ensuite (voir README "À retirer après migration").
 */
class LegacyAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'company' => ['required', 'string'],
        ]);

        $company = Company::where('slug', $data['company'])->first();
        $user = $company
            ? User::where('company_id', $company->id)->where('email', $data['email'])->first()
            : null;

        // Hash::check systématique (même sans utilisateur) : timing homogène.
        static $dummyHash;
        $dummyHash ??= Hash::make('timing-equalizer');
        $hash = $user?->password ?: $dummyHash;
        $valid = Hash::check($data['password'], $hash);

        if (! $user || ! $user->password || ! $valid) {
            return response()->json(['error' => 'INVALID_CREDENTIALS', 'message' => 'Identifiants invalides.'], 401);
        }

        return response()->json([
            'token' => $user->createToken('legacy')->plainTextToken,
            'token_type' => 'Bearer',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'company' => ['slug' => $user->company->slug, 'name' => $user->company->name],
        ]);
    }
}
