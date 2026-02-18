<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $role): mixed
    {
        if ($request->user()?->role !== $role) {
            return new JsonResponse(['message' => 'Yetkisiz erişim'], 403);
        }

        return $next($request);
    }
}
