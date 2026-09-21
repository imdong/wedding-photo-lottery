<?php

namespace Tests\Unit;

use App\SecureLotteryRandomSource;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecureLotteryRandomSourceTest extends TestCase
{
    public function test_returns_a_value_inside_the_requested_interval(): void
    {
        $value = (new SecureLotteryRandomSource)->next(2.4);

        $this->assertGreaterThanOrEqual(0.0, $value);
        $this->assertLessThan(2.4, $value);
    }

    #[DataProvider('invalidMaximums')]
    public function test_rejects_an_invalid_maximum(float $maximum): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SecureLotteryRandomSource)->next($maximum);
    }

    /** @return array<string, array{float}> */
    public static function invalidMaximums(): array
    {
        return [
            'zero' => [0.0],
            'negative' => [-1.0],
            'positive infinity' => [INF],
            'not a number' => [NAN],
        ];
    }
}
