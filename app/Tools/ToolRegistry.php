<?php

namespace App\Tools;

use App\Tools\Contracts\Tool;
use InvalidArgumentException;

/**
 * Resolves tools by name. The orchestrator iterates registry entries only:
 * adding a tool is one class + one registration line, zero core edits.
 */
class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    public function register(Tool $tool): void
    {
        $name = $tool->name();

        if ($name === '' || isset($this->tools[$name])) {
            throw new InvalidArgumentException("Cannot register tool [$name]: blank or duplicate name.");
        }

        $this->tools[$name] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): Tool
    {
        if (! isset($this->tools[$name])) {
            throw new InvalidArgumentException("Unknown tool [$name].");
        }

        return $this->tools[$name];
    }

    /** @return list<Tool> */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /** @return list<array{type:string,function:array<string,mixed>}> Ollama-format definitions. */
    public function definitions(): array
    {
        return array_map(
            fn (Tool $tool): array => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => (object) $tool->schema(),
                ],
            ],
            $this->all(),
        );
    }
}
