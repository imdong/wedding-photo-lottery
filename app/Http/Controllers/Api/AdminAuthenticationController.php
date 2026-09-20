<?php

namespace App\Http\Controllers\Api;

use App\AdminTokenService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminLoginRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthenticationController extends Controller
{
    public function __invoke(AdminLoginRequest $request, AdminTokenService $adminTokens): JsonResponse
    {
        $configuredPassword = (string) config('admin.password');

        if ($configuredPassword === '') {
            return response()->json([
                'message' => '管理员登录尚未配置。',
                'code' => 'admin_not_configured',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (! hash_equals($configuredPassword, $request->string('password')->toString())) {
            return response()->json([
                'message' => '管理员密码不正确。',
                'code' => 'invalid_admin_credentials',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $issuedToken = $adminTokens->issue();

        return response()->json([
            'token' => $issuedToken['token'],
            'expires_at' => $issuedToken['expires_at']->toIso8601String(),
        ]);
    }
}
