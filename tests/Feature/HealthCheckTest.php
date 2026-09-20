<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_check_reports_the_application_and_database_are_available(): void
    {
        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'application' => 'ok',
                'database' => 'ok',
            ]);
    }

    public function test_health_check_reports_when_the_database_is_unavailable(): void
    {
        config()->set('database.default', 'unavailable');

        $response = $this->getJson('/api/health');

        $response
            ->assertServiceUnavailable()
            ->assertExactJson([
                'status' => 'degraded',
                'application' => 'ok',
                'database' => 'unavailable',
            ]);
    }
}
