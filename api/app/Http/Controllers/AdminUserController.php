<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Administration de SA société uniquement (scope local forCurrentCompany).
 */
class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()->forCurrentCompany()->orderBy('id')
            ->get(['id', 'company_id', 'name', 'username', 'email', 'role', 'keycloak_id', 'email_verified_at']);

        return response()->json(['data' => $users]);
    }
}
