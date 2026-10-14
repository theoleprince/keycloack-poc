<?php

namespace App\Http\Controllers;

use App\Auth\Keycloak\KeycloakClaims;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant): JsonResponse
    {
        $user = $request->user();
        $claims = $request->attributes->get('keycloak_claims');
        $isKeycloak = $claims instanceof KeycloakClaims;
        $company = $tenant->company();

        return response()->json([
            'local_user_id' => $user->id,
            'sub' => $isKeycloak ? $claims->sub : $user->keycloak_id,
            'username' => $user->username ?? ($isKeycloak ? $claims->preferredUsername : null),
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $isKeycloak
                ? array_values(array_intersect($claims->roles, ['user', 'admin']))
                : [$user->role],
            'company' => ['slug' => $company->slug, 'name' => $company->name],
            'api_url' => $isKeycloak ? $claims->apiUrl : null,
            'auth_method' => $request->attributes->get('auth_method'),
        ]);
    }
}
