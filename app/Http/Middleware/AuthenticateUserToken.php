<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateUserToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();

        if ($plainTextToken === null || $plainTextToken === '') {
            return $this->unauthenticated();
        }

        $user = User::query()
            ->where('access_token_hash', hash('sha256', $plainTextToken))
            ->first();

        if ($user === null) {
            return $this->unauthenticated();
        }

        $request->setUserResolver(fn (): User => $user);

        return $next($request);
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json([
            'message' => '身份凭证无效，请重新输入手机号。',
            'code' => 'unauthenticated',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
