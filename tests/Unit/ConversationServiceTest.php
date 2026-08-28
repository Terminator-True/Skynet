<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function conversationService(): ConversationService
{
    return new ConversationService(new Conversation);
}

function conversationWithMessages(int $count): Conversation
{
    $user = User::create(['name' => 'Tester', 'email' => 'tester@example.com']);
    $conversation = Conversation::create([
        'session_id' => 'ses-test',
        'user_id' => $user->id,
    ]);

    for ($i = 1; $i <= $count; $i++) {
        $conversation->messages()->create([
            'role' => $i % 2 === 0 ? 'assistant' : 'user',
            'content' => "message {$i}",
        ]);
    }

    return $conversation;
}

it('returns the last ten messages oldest to newest by default', function () {
    $conversation = conversationWithMessages(25);

    $recent = conversationService()->recent($conversation);

    expect($recent)->toHaveCount(10)
        ->and($recent[0]['content'])->toBe('message 16')
        ->and($recent[9]['content'])->toBe('message 25');
});

it('honours an explicit limit', function () {
    $conversation = conversationWithMessages(25);

    $recent = conversationService()->recent($conversation, 5);

    expect($recent)->toHaveCount(5)
        ->and($recent[0]['content'])->toBe('message 21')
        ->and($recent[4]['content'])->toBe('message 25');
});

it('leaves history returning the full thread after recent()', function () {
    $conversation = conversationWithMessages(25);

    conversationService()->recent($conversation);
    $history = conversationService()->history($conversation);

    expect($history)->toHaveCount(25)
        ->and($history[0]['content'])->toBe('message 1')
        ->and($history[24]['content'])->toBe('message 25');
});

it('preserves the tool_trace shape in recent()', function () {
    $user = User::create(['name' => 'Tester', 'email' => 'tester@example.com']);
    $conversation = Conversation::create([
        'session_id' => 'ses-trace',
        'user_id' => $user->id,
    ]);

    $conversation->messages()->create([
        'role' => 'assistant',
        'content' => 'with trace',
        'tool_trace' => ['name' => 'calculate_sum', 'result' => ['sum' => 5]],
    ]);

    $recent = conversationService()->recent($conversation);

    expect($recent)->toHaveCount(1)
        ->and($recent[0]['role'])->toBe('assistant')
        ->and($recent[0]['content'])->toBe('with trace')
        ->and($recent[0]['tool_trace']['name'])->toBe('calculate_sum')
        ->and($recent[0]['tool_trace']['result']['sum'])->toBe(5);
});