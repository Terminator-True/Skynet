<?php

namespace App\Http\Controllers;

use App\Services\ChatLoopExhaustedException;
use App\Services\ChatOrchestrator;
use App\Services\ConversationService;
use App\Services\Ollama\OllamaConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\User;

class ChatController extends Controller
{
    /**
     * Sync JSON endpoint with optional session continuity (D2/D3). Stateful via
     * the orchestrator: an omitted session_id falls back to the default session
     * so single-turn behaviour is unchanged. Swappable to SSE later without
     * touching the orchestrator core.
     */
    public function store(Request $request, ChatOrchestrator $orchestrator): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1'],
            'session_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $orchestrator->handle($validated['message'], $validated['session_id'] ?? null);
        } catch (OllamaConnectionException $e) {
            return response()->json([
                'error' => 'ollama_unreachable',
                'message' => $e->getMessage(),
            ], 502);
        } catch (ChatLoopExhaustedException $e) {
            return response()->json([
                'error' => 'max_iterations',
                'message' => $e->getMessage(),
            ], 500);
        }

        return response()->json($result->toArray());
    }

    /**
     * Read-only display history for a session (last 10, oldest→newest).
     *
     * Mirrors the POST /chat payload shape ({role, content, tool_trace}) so
     * the frontend can preload history on mount without sending a message. An
     * omitted or blank session_id resolves the default session and returns an
     * empty history for a fresh thread.
     */
    public function history(Request $request, ConversationService $conversations): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $user = User::query()->first();
        $conversation = $user !== null
            ? $conversations->resolve($validated['session_id'] ?? null, $user->id)
            : null;

        return response()->json([
            'session_id' => $conversation?->session_id,
            'history' => $conversation !== null ? $conversations->recent($conversation) : [],
        ]);
    }
}
