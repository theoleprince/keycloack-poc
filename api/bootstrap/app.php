<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.user' => \App\Http\Middleware\SetTenantFromUser::class,
            'auth.any' => \App\Http\Middleware\AuthenticateAny::class,
            'auth.keycloak' => \App\Http\Middleware\EnsureValidKeycloakToken::class,
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);

        // La société courante doit être connue AVANT le route model binding,
        // sinon /notes/{note} serait résolu hors périmètre société.
        foreach ([
            \App\Http\Middleware\AuthenticateAny::class,
            \App\Http\Middleware\EnsureValidKeycloakToken::class,
            \App\Http\Middleware\SetTenantFromUser::class,
        ] as $tenantMiddleware) {
            $middleware->prependToPriorityList(
                before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
                prepend: $tenantMiddleware,
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API : toujours du JSON, jamais de redirection vers une page de login.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => 'UNAUTHENTICATED', 'message' => 'Authentification requise.'], 401);
            }
        });

        // 404 générique : ne révèle ni le modèle ni l'existence de la ressource.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['error' => 'NOT_FOUND', 'message' => 'Ressource introuvable.'], 404);
            }
        });
    })->create();
