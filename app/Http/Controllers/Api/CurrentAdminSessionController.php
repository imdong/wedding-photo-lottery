<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CurrentAdminSessionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'authenticated' => true,
        ]);
    }
}
