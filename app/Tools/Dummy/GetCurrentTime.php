<?php

namespace App\Tools\Dummy;

use App\Tools\Contracts\Tool;

class GetCurrentTime implements Tool
{
    public function name(): string
    {
        return 'get_current_time';
    }

    public function description(): string
    {
        return 'Returns the current server date and time in ISO-8601 format. Use when the user asks for the current time or date.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass,
        ];
    }

    public function execute(array $args): array
    {
        return [
            'iso_datetime' => now()->toIso8601String(),
        ];
    }
}
