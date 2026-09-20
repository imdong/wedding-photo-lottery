<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FrontendRoutesTest extends TestCase
{
    #[TestWith(['/'])]
    #[TestWith(['/gallery'])]
    #[TestWith(['/draw'])]
    #[TestWith(['/admin/review'])]
    public function test_frontend_route_renders_the_vue_application(string $path): void
    {
        $response = $this->get($path);

        $response->assertViewIs('app');
    }
}
