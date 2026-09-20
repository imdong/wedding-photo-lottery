<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterPhoneRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PhoneAuthenticationController extends Controller
{
    public function __invoke(RegisterPhoneRequest $request): JsonResponse
    {
        $plainTextToken = Str::random(64);

        $user = DB::transaction(function () use ($request, $plainTextToken): User {
            $user = User::firstOrCreate(
                ['phone' => $request->string('phone')->toString()],
                ['invite_code' => Str::random(8)],
            );

            $user->forceFill([
                'access_token_hash' => hash('sha256', $plainTextToken),
            ])->save();

            return $user;
        });

        return response()->json([
            'token' => $plainTextToken,
            'user' => UserResource::make($user)->resolve($request),
        ]);
    }
}
