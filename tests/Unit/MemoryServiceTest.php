<?php

use App\Models\MemoryEntry;
use App\Models\User;
use App\Services\MemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeEmbeddingProvider;

uses(RefreshDatabase::class);

function memoryService(): MemoryService
{
    return new MemoryService(
        new FakeEmbeddingProvider,
        new MemoryEntry,
    );
}

it('returns 0.0 cosine for zero or empty vectors', function () {
    expect(MemoryService::cosine([], []))->toBe(0.0)
        ->and(MemoryService::cosine([0.0, 0.0, 0.0], [1.0, 2.0, 3.0]))->toBe(0.0)
        ->and(MemoryService::cosine([1.0, 2.0], []))->toBe(0.0);
});

it('returns 1.0 cosine for identical vectors', function () {
    expect(MemoryService::cosine([1.0, 2.0, 3.0], [1.0, 2.0, 3.0]))->toBe(1.0);
});

it('returns 0.0 cosine for orthogonal vectors', function () {
    expect(MemoryService::cosine([1.0, 0.0], [0.0, 1.0]))->toBe(0.0);
});

it('persists content with an embedding on remember', function () {
    $user = User::create(['name' => 'Tester', 'email' => 'tester@example.com']);
    $service = memoryService();

    $service->remember('prefiere café');

    $this->assertDatabaseHas('memory_entries', [
        'user_id' => $user->id,
        'content' => 'prefiere café',
    ]);
});

it('recalls the most similar stored entry first', function () {
    User::create(['name' => 'Tester', 'email' => 'tester@example.com']);
    $service = memoryService();

    $service->remember('prefiere café');
    $service->remember('odio la lluvia');

    $result = $service->recall('café', 1, 200);

    expect($result)->toBe('- prefiere café');
});

it('respects top-k and char-cap in recall', function () {
    User::create(['name' => 'Tester', 'email' => 'tester@example.com']);
    $service = memoryService();

    $service->remember('prefiere café con leche y azúcar');
    $service->remember('le gusta viajar a la montaña');
    $service->remember('toma mate por la tarde');

    $result = $service->recall('café leche', 2, 10);

    // The most relevant entry ranks first and is char-capped.
    expect(str_starts_with($result, '- prefiere c'))
        ->and(substr_count($result, "\n"))->toBe(1)
        ->and(substr_count($result, "\n- "))->toBe(1);

    // Each recalled line must stay within charCap (excluding the "- " prefix).
    foreach (explode("\n", $result) as $line) {
        expect(mb_strlen(substr($line, 2)))->toBeLessThanOrEqual(10);
    }
});
