<?php

namespace App\Tools\Contracts;

interface Tool
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> JSON Schema object. */
    public function schema(): array;

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed> Structured result.
     */
    public function execute(array $args): array;
}
