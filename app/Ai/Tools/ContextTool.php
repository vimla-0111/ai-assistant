<?php

namespace App\Ai\Tools;

use App\Ai\Rag\QdrantClient;
use App\Ai\Rag\SchemaSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Tools\Request;
use Stringable;

class ContextTool implements Tool
{
    private const string EMBED_MODEL = 'text-embedding-3-small';

    private const int RESUME_LIMIT = 5;

    public function __construct(
        private readonly QdrantClient $qdrantResume,
        private readonly SchemaSearchService $schemaSearch,
    ) {}

    public function description(): Stringable|string
    {
        return 'Fetches additional context when the information provided in the initial prompt is insufficient. '
            .'Use type="schema" to fetch more database table definitions for building accurate queries. '
            .'Use type="resume" to fetch candidate resume excerpts for skills, experience, or education queries. '
            .'Use type="both" when you need database schema AND resume content at the same time — provide separate, '
            .'focused queries for each source so that the most relevant chunks are returned from each collection.';
    }

    public function handle(Request $request): Stringable|string
    {
        $type = $request->string('type')->toString();
        $querySchema = $request->string('query_schema', '')->toString();
        $queryResume = $request->string('query_resume', '')->toString();

        Log::info('ContextTool: invoked', [
            'type' => $type,
            'query_schema' => $querySchema !== '' ? $querySchema : null,
            'query_resume' => $queryResume !== '' ? $queryResume : null,
        ]);

        $result = [];

        if (in_array($type, ['schema', 'both'], strict: true) && $querySchema !== '') {
            $schemaContext = $this->schemaSearch->findRelevantSchema($querySchema);

            if ($schemaContext !== '') {
                $result['schema'] = $schemaContext;
            }
        }

        if (in_array($type, ['resume', 'both'], strict: true) && $queryResume !== '') {
            $resumeHits = $this->searchResumes($queryResume);

            if (! empty($resumeHits)) {
                $result['resume'] = $resumeHits;
            }
        }

        if (empty($result)) {
            Log::warning('ContextTool: no context found', [
                'type' => $type,
                'query_schema' => $querySchema !== '' ? $querySchema : null,
                'query_resume' => $queryResume !== '' ? $queryResume : null,
            ]);

            return json_encode(['message' => 'No relevant context found for the given query. Try rephrasing.']);
        }

        Log::info('ContextTool: returning context', [
            'type' => $type,
            'has_schema' => isset($result['schema']),
            'resume_hits' => isset($result['resume']) ? count($result['resume']) : 0,
        ]);

        return json_encode($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->description(
                    'The type of context to retrieve. '
                    .'"schema" = database table definitions for query building. '
                    .'"resume" = candidate resume excerpts for skills/experience queries. '
                    .'"both" = fetch from both sources simultaneously using separate focused queries.'
                )
                ->enum(['schema', 'resume', 'both'])
                ->required(),

            'query_schema' => $schema->string()
                ->description(
                    'Natural language query to search database table schemas. '
                    .'Required when type is "schema" or "both". '
                    .'E.g. "candidate pipeline status and requisition tables" or "department and level join for users".'
                ),

            'query_resume' => $schema->string()
                ->description(
                    'Natural language query to search candidate resumes. '
                    .'Required when type is "resume" or "both". '
                    .'E.g. "PHP developer with Laravel and Vue experience" or "AWS certified cloud engineer".'
                ),
        ];
    }

    /**
     * Search the candidate_resumes Qdrant collection and return formatted hits.
     *
     * @return array<int, array{candidate_id: int, candidate_name: string, relevance: float, excerpt: string}>
     */
    private function searchResumes(string $query): array
    {
        try {
            $vector = $this->embed($query);
            $results = $this->qdrantResume->search($vector, self::RESUME_LIMIT);

            return array_map(fn (array $hit): array => [
                'candidate_id' => $hit['payload']['candidate_id'],
                'candidate_name' => $hit['payload']['candidate_name'],
                'relevance' => round($hit['score'], 3),
                'excerpt' => $hit['payload']['chunk_text'],
            ], $results);
        } catch (\Throwable $e) {
            Log::error('ContextTool: resume search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return float[]
     */
    private function embed(string $text): array
    {
        Log::info('ContextTool: generating embedding', ['text' => $text]);

        $response = Embeddings::for([$text])
            ->timeout(30)
            ->generate(Lab::OpenRouter, self::EMBED_MODEL);

        return $response->first();
    }
}
