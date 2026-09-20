<?php

namespace App\Providers;

use App\OssConfiguration;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            OssConfiguration::class,
            fn (): OssConfiguration => OssConfiguration::fromConfig(),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('admin-login', function (Request $request): Limit {
            return Limit::perMinute(5)
                ->by((string) $request->ip())
                ->response(fn (Request $request, array $headers): JsonResponse => response()->json([
                    'message' => '管理员登录尝试过于频繁，请稍后再试。',
                    'code' => 'too_many_requests',
                ], Response::HTTP_TOO_MANY_REQUESTS, $headers));
        });

        RateLimiter::for('phone-registration', function (Request $request): Limit {
            return Limit::perMinute(10)
                ->by($request->string('phone')->trim()->toString().'|'.$request->ip())
                ->response(fn (Request $request, array $headers): JsonResponse => response()->json([
                    'message' => '请求过于频繁，请稍后再试。',
                    'code' => 'too_many_requests',
                ], Response::HTTP_TOO_MANY_REQUESTS, $headers));
        });
    }
}
