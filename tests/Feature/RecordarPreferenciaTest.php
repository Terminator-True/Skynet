<?php

use App\Models\MemoryEntry;
use App\Models\User;
use App\Services\Ollama\EmbeddingProvider;
use App\Tools\RecordarPreferencia;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeEmbeddingProvider;

uses(RefreshDatabase::class);

it('stores a preference and returns a confirmation when the tool runs', function () {
    // Offline embed seam: zero HTTP egress while persisting a real row.
    app()->bind(EmbeddingProvider::class, FakeEmbeddingProvider::class);

    $user = User::factory()->create();
    $this->actingAs($user);

    $tool = app(ToolRegistry::class)->get('recordar_preferencia');
    expect($tool)->toBeInstanceOf(RecordarPreferencia::class);

    $result = $tool->execute(['preferencia' => 'Prefiero el café negro sin azúcar']);

    expect($result)->toBe(['status' => 'saved', 'preferencia' => 'Prefiero el café negro sin azúcar']);

    $stored = MemoryEntry::query()->where('user_id', $user->id)->first();
    expect($stored)->not->toBeNull()
        ->and($stored->content)->toBe('Prefiero el café negro sin azúcar')
        ->and($stored->embedding)->toBeArray()
        ->and($stored->embedding)->not->toBeEmpty();
});

it('is registered in the container-bound registry with the expected schema', function () {
    $registry = app(ToolRegistry::class);

    expect($registry->has('recordar_preferencia'))->toBeTrue();

    $schema = $registry->get('recordar_preferencia')->schema();
    expect($schema['properties']['preferencia']['type'])->toBe('string')
        ->and($schema['required'])->toBe(['preferencia']);
});
