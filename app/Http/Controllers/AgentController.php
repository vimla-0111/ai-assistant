<?php

namespace App\Http\Controllers;

use App\Ai\Agents\ProjectAssistant;
use App\Http\Requests\AgentPromptRequest;
use App\Models\AgentConversationMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Ai\Enums\Lab;
use Throwable;

class AgentController extends Controller
{
    /**
     * Display the agent chat interface.
     */
    public function index(Request $request): View
    {
        return view('agent.index', [
            'messages' => $this->messagesForUser($request->user()->id),
        ]);
    }

    /**
     * Send a prompt to the agent and return the response payload.
     */
    public function store(AgentPromptRequest $request): JsonResponse
    {
        $user = $request->user();
        $prompt = $request->validated('prompt');

        try {
            // using openrouter provider
            $response = ProjectAssistant::make(user: $user)
                ->continueLastConversation($user)
                ->prompt(
                    $prompt,
                    provider: Lab::OpenRouter,
                    model: 'nvidia/nemotron-3-super-120b-a12b:free',
                    timeout: 120,
                );

            // using openai provider
            // $response = ProjectAssistant::make(user: $user)
            //     ->continueLastConversation($user)
            //     ->prompt(
            //         $prompt,
            //         provider: Lab::OpenAI,
            //         timeout: 120,
            //     );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The agent is unavailable right now. Please try again.',
            ], 502);
        }
        Log::info('Agent response', [
            'text' => $response->text,
            'conversation_id' => $response->conversationId,
            'steps_count' => count($response->steps ?? []),
        ]);

        return response()->json([
            'answer' => $response->text ?? 'No result found. Try rephrasing your question or ask something else.',
            'conversation_id' => $response->conversationId,
        ]);
    }

    /**
     * Get the latest conversation messages for the current user.
     *
     * @return array<int, array{id: string, role: string, content: string, created_at: string}>
     */
    protected function messagesForUser(int $userId): array
    {
        return AgentConversationMessage::query()
            ->where('user_id', $userId)
            ->whereIn('role', ['assistant', 'user'])
            ->latest()
            ->limit(24)
            ->get(['id', 'role', 'content', 'created_at'])
            ->reverse()
            ->values()
            ->map(fn(AgentConversationMessage $message): array => [
                'id' => (string) $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'created_at' => $message->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ])
            ->all();
    }
}
