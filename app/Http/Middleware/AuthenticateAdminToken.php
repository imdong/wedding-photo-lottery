<?php

namespace App\Http\Middleware;

use App\AdminTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdminToken
{
    public function __construct(private readonly AdminTokenService $adminTokens) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();

        if (
            $plainTextToken === null
            || $plainTextToken === ''
            || ! $this->adminTokens->isValid($plainTextToken)
        ) {
            return $this->unauthenticated();
        }

        return $next($request);
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json([
            'message' => '管理员身份凭证无效或已过期。',
            'code' => 'admin_unauthenticated',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
