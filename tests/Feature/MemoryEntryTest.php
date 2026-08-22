<?php

use App\Models\MemoryEntry;
use App\Models\User;
use App\Services\MemoryService;
use App\Services\Ollama\EmbeddingProvider;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeEmbeddingProvider;

beforeEach(function () {
    $this->app->bind(EmbeddingProvider::class, FakeEmbeddingProvider::class);
});

it('cascade-deletes memory entries when the user is deleted', function () {
    $user = User::create(['name' => 'Tester', 'email' => 'tester@example.com']);
    app(MemoryService::class)->remember('prefiere café');

    expect(MemoryEntry::where('user_id', $user->id)->count())->toBe(1);

    $user->delete();

    expect(MemoryEntry::count())->toBe(0);
});

it('performs a full store + recall flow with zero HTTP egress', function () {
    User::create(['name' => 'Tester', 'email' => 'tester@example.com']);
    $service = app(MemoryService::class);

    $service->remember('prefiere café');

    Http::fake(); // intercept everything: any real request becomes visible

    $recalled = $service->recall('café', 1, 200);

    expect($recalled)->toBe('- prefiere café');
    Http::assertNothingSent();
});
