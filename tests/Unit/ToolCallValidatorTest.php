<?php

use App\Tools\Dummy\CalculateSum;
use App\Tools\Dummy\GetCurrentTime;
use App\Tools\Dummy\GetWeatherMock;
use Tests\Eval\ToolCallValidator;

it('validates zero-arg schemas (get_current_time)', function () {
    $schema = (new GetCurrentTime)->schema();

    expect(ToolCallValidator::isValid($schema, []))->toBeTrue()
        // Unknown extra keys are tolerated (models sometimes over-specify).
        ->and(ToolCallValidator::isValid($schema, ['timezone' => 'UTC']))->toBeTrue();
});

it('validates two-number schemas (calculate_sum)', function () {
    $schema = (new CalculateSum)->schema();

    expect(ToolCallValidator::isValid($schema, ['a' => 2, 'b' => 3]))->toBeTrue()
        ->and(ToolCallValidator::isValid($schema, ['a' => 2.5, 'b' => 0]))->toBeTrue()
        ->and(ToolCallValidator::validate($schema, ['a' => 2]))->toContain('Missing required argument [b].')
        ->and(ToolCallValidator::validate($schema, ['a' => 'two', 'b' => 3]))->toContain('Argument [a] must be of type number.')
        ->and(ToolCallValidator::validate($schema, ['a' => true, 'b' => 'x']))->toHaveCount(2);
});

it('validates single-string schemas (get_weather_mock)', function () {
    $schema = (new GetWeatherMock)->schema();

    expect(ToolCallValidator::isValid($schema, ['city' => 'Rosario']))->toBeTrue()
        ->and(ToolCallValidator::validate($schema, []))->toContain('Missing required argument [city].')
        ->and(ToolCallValidator::validate($schema, ['city' => 123]))->toContain('Argument [city] must be of type string.');
});

it('treats empty-string required values as missing', function () {
    $schema = (new GetWeatherMock)->schema();

    expect(ToolCallValidator::isValid($schema, ['city' => '']))->toBeFalse();
});
