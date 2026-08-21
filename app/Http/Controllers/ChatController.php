<?php

namespace App\Http\Controllers;

use App\Services\ChatLoopExhaustedException;
use App\Services\ChatOrchestrator;
use App\Services\Ollama\OllamaConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    /**
     * Stateless sync JSON endpoint. Zero DB access by design; swappable to
     * SSE later without touching the orchestrator core.
     */
    public function store(Request $request, ChatOrchestrator $orchestrator): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1'],
        ]);

        try {
            $result = $orchestrator->handle($validated['message']);
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
}
