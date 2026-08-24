<?php

use App\Tools\Contracts\Tool;
use App\Tools\Dummy\CalculateSum;
use App\Tools\Dummy\GetCurrentTime;
use App\Tools\Dummy\GetWeatherMock;
use App\Tools\ToolRegistry;

it('registers, resolves, and lists tools', function () {
    $registry = new ToolRegistry;

    $tool = new CalculateSum;
    $registry->register($tool);

    expect($registry->has('calculate_sum'))->toBeTrue()
        ->and($registry->has('nope'))->toBeFalse()
        ->and($registry->get('calculate_sum'))->toBe($tool)
        ->and($registry->all())->toHaveCount(1);
});

it('rejects duplicate registrations', function () {
    $registry = new ToolRegistry;
    $registry->register(new CalculateSum);

    $registry->register(new CalculateSum);
})->throws(InvalidArgumentException::class);

it('builds Ollama-format definitions from registered tools', function () {
    $registry = new ToolRegistry;
    $registry->register(new GetCurrentTime);
    $registry->register(new GetWeatherMock);

    $definitions = collect($registry->definitions())->pluck('function.name')->all();

    expect($definitions)->toBe(['get_current_time', 'get_weather_mock'])
        ->and($registry->definitions()[0]['function']['parameters'])->toBeObject()
        ->and($registry->definitions()[1]['function']['parameters']->required)->toBe(['city']);
});

it('exposes the three dummy tools with distinct argument shapes', function () {
    $registry = new ToolRegistry;
    $registry->register(new GetCurrentTime);
    $registry->register(new CalculateSum);
    $registry->register(new GetWeatherMock);

    // Time: zero args.
    $time = $registry->get('get_current_time');
    expect($time->schema()['properties'])->toBeObject();

    $output = $time->execute([]);
    expect($output)->toHaveKey('iso_datetime')
        ->and(DateTimeImmutable::createFromFormat(DATE_ISO8601, $output['iso_datetime']))->toBeInstanceOf(DateTimeImmutable::class);

    // Sum: two numbers.
    $sum = $registry->get('calculate_sum');
    expect($sum->execute(['a' => 2, 'b' => 3.5]))->toBe(['a' => 2.0, 'b' => 3.5, 'sum' => 5.5]);

    // Weather: one string, deterministic output.
    $weather = $registry->get('get_weather_mock');
    $first = $weather->execute(['city' => 'Rosario']);
    $second = $weather->execute(['city' => 'Rosario']);

    expect($first)->toHaveKeys(['city', 'condition', 'temperature_c'])
        ->and($first)->toBe($second)
        ->and($second['condition'])->toBeIn(['sunny', 'cloudy', 'rainy', 'windy'])
        ->and($second['temperature_c'])->toBeFloat();
});

it('rejects invalid dummy-tool arguments', function () {
    (new GetWeatherMock)->execute(['city' => '  ']);
})->throws(InvalidArgumentException::class);

it('proves a fourth tool registers with zero orchestrator edits', function () {
    $fourth = new class implements Tool
    {
        public function name(): string
        {
            return 'echo_fourth';
        }

        public function description(): string
        {
            return 'Echoes input. Proves extensibility.';
        }

        public function schema(): array
        {
            return ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]];
        }

        public function execute(array $args): array
        {
            return ['echo' => $args['text']];
        }
    };

    // The container-bound registry ships with the 3 dummy tools + calendar + Gmail + Amazon + web + memory tools.
    $registry = app(ToolRegistry::class);
    expect(count($registry->all()))->toBe(9);

    $registry->register($fourth);

    expect(count($registry->all()))->toBe(10)
        ->and($registry->has('echo_fourth'))->toBeTrue()
        ->and(collect($registry->definitions())->pluck('function.name'))->toContain('echo_fourth');
});
