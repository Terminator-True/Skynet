<?php

namespace App\Tools\Dummy;

use App\Tools\Contracts\Tool;

class CalculateSum implements Tool
{
    public function name(): string
    {
        return 'calculate_sum';
    }

    public function description(): string
    {
        return 'Adds two numbers and returns the sum. Use for any arithmetic addition request.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'number'],
                'b' => ['type' => 'number'],
            ],
            'required' => ['a', 'b'],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{a: float, b: float, sum: float}
     */
    public function execute(array $args): array
    {
        if (! isset($args['a'], $args['b']) || ! is_numeric($args['a']) || ! is_numeric($args['b'])) {
            throw new \InvalidArgumentException('calculate_sum requires numeric arguments a and b.');
        }

        $a = (float) $args['a'];
        $b = (float) $args['b'];

        return [
            'a' => $a,
            'b' => $b,
            'sum' => $a + $b,
        ];
    }
}
