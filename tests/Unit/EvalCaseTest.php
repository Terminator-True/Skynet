<?php

use Tests\Eval\EvalCase;

function toolCallCase(): EvalCase
{
    return new EvalCase(
        prompt: 'test',
        expectedToolName: 'calculate_sum',
        semantics: fn (array $call): bool => $call['result']['sum'] == 5,
    );
}

it('passes a tool case on correct name, schema-valid args, matching semantics', function () {
    expect(toolCallCase()->judge([
        'reply' => '5!',
        'tool_calls' => [['name' => 'calculate_sum', 'arguments' => ['a' => 2, 'b' => 3], 'schemaValid' => true, 'result' => ['sum' => 5]]],
    ]))->toBeTrue();
});

it('fails a tool case on wrong name, schema-invalid args, or wrong semantics', function () {
    $valid = ['name' => 'calculate_sum', 'arguments' => ['a' => 2, 'b' => 3], 'schemaValid' => true, 'result' => ['sum' => 5]];

    expect(toolCallCase()->judge(['reply' => '', 'tool_calls' => [array_merge($valid, ['name' => 'get_current_time'])]]))->toBeFalse()
        ->and(toolCallCase()->judge(['reply' => '', 'tool_calls' => [array_merge($valid, ['schemaValid' => false])]]))->toBeFalse()
        ->and(toolCallCase()->judge(['reply' => '', 'tool_calls' => [array_merge($valid, ['result' => ['sum' => 6]])]]))->toBeFalse();
});

it('requires an empty trace plus non-empty reply for no-tool cases', function () {
    $case = new EvalCase(prompt: 'test', expectedToolName: null);

    expect($case->judge(['reply' => 'Hello!', 'tool_calls' => []]))->toBeTrue()
        ->and($case->judge(['reply' => '', 'tool_calls' => []]))->toBeFalse()
        ->and($case->judge(['reply' => 'Hi', 'tool_calls' => [['name' => 'x', 'arguments' => [], 'schemaValid' => true, 'result' => []]]]))->toBeFalse();
});
