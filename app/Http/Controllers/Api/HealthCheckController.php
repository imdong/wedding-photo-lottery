<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            return response()->json([
                'status' => 'degraded',
                'application' => 'ok',
                'database' => 'unavailable',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'status' => 'ok',
            'application' => 'ok',
            'database' => 'ok',
        ]);
    }
}
