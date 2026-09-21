<?php

namespace Tests\Support;

trait AssertsJsonValues
{
    protected function assertJsonValueSame(mixed $expected, mixed $actual): void
    {
        $this->assertSame(
            $this->normalizeJsonValue($expected),
            $this->normalizeJsonValue($actual),
        );
    }

    private function normalizeJsonValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(
            fn (mixed $item): mixed => $this->normalizeJsonValue($item),
            $value,
        );
    }
}
