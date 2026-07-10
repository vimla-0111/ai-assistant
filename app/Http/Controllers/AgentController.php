<?php

namespace App\Http\Controllers;

use App\Ai\Agents\ProjectAssistant;
use App\Ai\Rag\QdrantClient;
use App\Ai\Rag\SchemaSearchService;
use App\Http\Requests\AgentPromptRequest;
use App\Models\AgentConversationMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Throwable;

class AgentController extends Controller
{
    private const string EMBED_MODEL = 'text-embedding-3-small';

    /**
     * How many resume chunks to pre-fetch for first-prompt injection
     * when the user explicitly asks for resume/CV content.
     */
    private const int RESUME_INJECTION_LIMIT = 5;

    /**
     * Minimum score for a resume hit to be included in the injected context.
     */
    private const float RESUME_SCORE_THRESHOLD = 0.40;

    /**
     * Number of messages to display in the chat UI history.
     */
    private const int MESSAGE_HISTORY_LIMIT = 24;

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
     *
     * Embeds the user prompt once, then searches both Qdrant collections.
     * If the user explicitly mentions "cv" or "resume", or if the resume
     * collection returns a high-confidence match (score >= 0.40), resume
     * chunks are injected into the first prompt. Otherwise the most relevant
     * table-schema definitions are injected so the model can build accurate
     * DB queries. The ContextTool is available for the model to call if the
     * pre-injected context turns out to be insufficient.
     */
    public function store(AgentPromptRequest $request): JsonResponse
    {
        $user = $request->user();
        $prompt = $request->validated('prompt');
        $promptLower = strtolower(trim($prompt));

        $isGreeting = strlen($promptLower) < 10
            && in_array($promptLower, ['hi', 'hello', 'hey'], strict: true);

        $initialContext = '';

        if (! $isGreeting) {
            $initialContext = $this->resolveInitialContext($prompt, $promptLower);
        }

        Log::info('AgentController: initial context sent to first prompt', [
            'prompt' => $prompt,
            'context_length' => strlen($initialContext),
            'context' => $initialContext !== '' ? $initialContext : '(none — greeting or empty)',
        ]);

        try {
            // $response = ProjectAssistant::make(user: $user)
            //     ->withInitialContext($initialContext)
            //     ->continueLastConversation($user)
            //     ->prompt(
            //         $prompt,
            //         provider: Lab::Groq,
            //         model: 'openai/gpt-oss-20b',
            //         timeout: 120,
            //     );

            // openrouter
            $response = ProjectAssistant::make(user: $user)
                ->withInitialContext($initialContext)
                ->continueLastConversation($user)
                ->prompt(
                    $prompt,
                    provider: Lab::OpenRouter,
                    // model: 'nvidia/nemotron-3-super-120b-a12b:free',
                    model: 'poolside/laguna-m.1:free',
                    timeout: 120,
                );
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
     * Embed the user prompt once, then decide whether to inject resume chunks
     * or schema chunks into the first prompt.
     *
     * Resume chunks are only injected when the user explicitly uses the words
     * "resume" or "cv" in their prompt. All other queries receive schema context
     * so the model can build accurate DB queries. The ContextTool is available
     * as a fallback if the injected context is insufficient.
     */
    private function resolveInitialContext(string $prompt, string $promptLower): string
    {
        $hasResumeKeyword = str_contains($promptLower, 'resume')
            || str_contains($promptLower, ' cv ')
            || str_starts_with($promptLower, 'cv ');

        // Embed the prompt once and reuse the vector for both paths.
        $vector = $this->embedPrompt($prompt);

        if ($hasResumeKeyword) {
            $resumeContext = $this->fetchResumeContext($vector);

            if ($resumeContext !== '') {
                Log::info('AgentController: injecting resume context (keyword match)', ['prompt' => $prompt]);

                return $resumeContext;
            }
        }

        // Default: always inject schema context for DB-oriented queries.
        $schemaContext = app(SchemaSearchService::class)->findRelevantSchemaByVector($vector);
        Log::info('AgentController: injecting schema context', ['prompt' => $prompt]);

        return $schemaContext;
    }

    /**
     * Fetch resume chunks from Qdrant using a pre-computed vector and return
     * them formatted as a string suitable for prompt injection.
     */
    private function fetchResumeContext(array $vector): string
    {
        $results = app(QdrantClient::class)->search($vector, self::RESUME_INJECTION_LIMIT);

        return $this->formatResumeHits($results);
    }

    /**
     * @param  array<int, array{id: int, score: float, payload: array}>  $hits
     */
    private function formatResumeHits(array $hits): string
    {
        $hits = array_filter($hits, fn (array $hit) => ($hit['score'] ?? 0) >= self::RESUME_SCORE_THRESHOLD);

        if (empty($hits)) {
            return '';
        }

        $lines = array_map(fn (array $hit): string => sprintf(
            "Candidate: %s (ID: %d) | Relevance: %.2f\nExcerpt: %s",
            $hit['payload']['candidate_name'] ?? 'Unknown',
            $hit['payload']['candidate_id'] ?? 0,
            $hit['score'],
            $hit['payload']['chunk_text'] ?? '',
        ), $hits);

        return "Relevant resume excerpts:\n\n".implode("\n\n---\n\n", $lines);
    }

    /**
     * Generate an embedding vector for the given text.
     *
     * @return float[]
     */
    private function embedPrompt(string $text): array
    {
        Log::info('AgentController: generating embedding', ['text' => $text]);

        $response = Embeddings::for([$text])
            ->timeout(30)
            ->generate(Lab::OpenRouter, self::EMBED_MODEL);

        return $response->first();
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
            ->limit(self::MESSAGE_HISTORY_LIMIT)
            ->get(['id', 'role', 'content', 'created_at'])
            ->reverse()
            ->values()
            ->map(fn (AgentConversationMessage $message): array => [
                'id' => (string) $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'created_at' => $message->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ])
            ->all();
    }
}
