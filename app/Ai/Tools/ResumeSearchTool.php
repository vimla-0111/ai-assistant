<?php

namespace App\Ai\Tools;

use App\Ai\Rag\QdrantClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ResumeSearchTool implements Tool
{
    private const EMBED_MODEL = 'text-embedding-3-small';

    public function description(): Stringable|string
    {
        return 'Search candidate resumes using semantic similarity. '
            .'Use this when the user asks about candidate skills, technologies, work experience, '
            .'education, certifications, or anything found in a resume or CV. '
            .'Input a natural language query. Returns the most relevant resume excerpts with candidate name and ID.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = (string) $request->string('query');
        $candidateId = $request->integer('candidate_id', 0);
        $limit = min($request->integer('limit', 5), 10);

        Log::info('ResumeSearchTool: invoked', [
            'query' => $query,
            'candidate_id' => $candidateId ?: 'all',
            'limit' => $limit,
        ]);

        try {
            Log::info('ResumeSearchTool: generating query embedding', [
                'model' => self::EMBED_MODEL,
                'query' => $query,
            ]);

            $vector = $this->embed($query);

            Log::info('ResumeSearchTool: query embedded', [
                'vector_dimensions' => count($vector),
            ]);

            $filter = [];
            if ($candidateId > 0) {
                $filter = [
                    'must' => [[
                        'key' => 'candidate_id',
                        'match' => ['value' => $candidateId],
                    ]],
                ];
            }

            $results = app(QdrantClient::class)->search($vector, $limit, $filter);

            if (empty($results)) {
                Log::warning('ResumeSearchTool: no results found', ['query' => $query]);

                return json_encode(['message' => 'No relevant resume content found for this query.']);
            }

            $hits = array_map(fn (array $hit) => [
                'candidate_id' => $hit['payload']['candidate_id'],
                'candidate_name' => $hit['payload']['candidate_name'],
                'relevance' => round($hit['score'], 3),
                'excerpt' => $hit['payload']['chunk_text'],
            ], $results);

            Log::info('ResumeSearchTool: returning results', [
                'query' => $query,
                'hits' => count($hits),
                'candidates' => array_unique(array_column($hits, 'candidate_name')),
                'top_score' => $hits[0]['relevance'] ?? null,
            ]);

            return json_encode(['results' => $hits, 'count' => count($hits)]);

        } catch (\Throwable $e) {
            Log::error('ResumeSearchTool: error', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return json_encode(['error' => $e->getMessage()]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Natural language query to search resumes. E.g. "PHP developer with Laravel experience" or "AWS certified engineer".')
                ->required(),

            'candidate_id' => $schema->integer()
                ->description('Optional. Filter results to a specific candidate ID.')
                ->default(0),

            'limit' => $schema->integer()
                ->description('Number of results to return (max 10).')
                ->default(5),
        ];
    }

    /**
     * @return float[]
     */
    private function embed(string $text): array
    {
        $response = Http::withToken(config('ai.providers.openrouter.key'))
            ->timeout(30)
            ->post('https://openrouter.ai/api/v1/embeddings', [
                'model' => self::EMBED_MODEL,
                'input' => [$text],
            ])->throw();

        return $response->json('data.0.embedding');
    }
}
