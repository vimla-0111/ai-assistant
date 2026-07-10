<?php

namespace App\Ai\Rag;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;

class SchemaSearchService
{
    private const string EMBED_MODEL = 'text-embedding-3-small';

    /**
     * Hard minimum cosine similarity — results below this score are discarded.
     */
    private const float SCORE_THRESHOLD = 0.22;

    private int $topK;

    public function __construct(private QdrantClient $qdrant)
    {
        $this->topK = (int) config('ai.qdrant.schema_top_k', 15);
    }

    /**
     * Embed the user's prompt, run a similarity search against the schema
     * collection, and return a formatted string of the most relevant table
     * definitions ready to be injected into the agent's instructions.
     */
    public function findRelevantSchema(string $prompt, ?int $limit = null): string
    {
        $limit = $limit ?? $this->topK;

        Log::info('SchemaSearchService: searching for relevant schema', [
            'prompt' => $prompt,
            'limit' => $limit,
        ]);

        try {
            $vector = $this->embed($prompt);

            return $this->searchByVector($vector, $limit);
        } catch (\Throwable $e) {
            Log::error('SchemaSearchService: search failed', [
                'error' => $e->getMessage(),
                'prompt' => $prompt,
            ]);

            return '';
        }
    }

    /**
     * Run a similarity search using a pre-computed embedding vector.
     * Use this when the caller has already embedded the prompt (e.g. to
     * reuse the same vector across multiple Qdrant collection searches).
     *
     * @param  float[]  $vector
     */
    public function findRelevantSchemaByVector(array $vector, ?int $limit = null): string
    {
        $limit = $limit ?? $this->topK;

        try {
            return $this->searchByVector($vector, $limit);
        } catch (\Throwable $e) {
            Log::error('SchemaSearchService: vector search failed', [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Core search logic shared by both public methods.
     * Searches Qdrant, applies the score threshold, and formats the results.
     *
     * @param  float[]  $vector
     */
    private function searchByVector(array $vector, int $limit): string
    {
        $results = $this->qdrant->search($vector, $limit);

        if (empty($results)) {
            Log::warning('SchemaSearchService: no schema matches found');

            return '';
        }

        $results = array_filter($results, fn (array $hit) => ($hit['score'] ?? 0) >= self::SCORE_THRESHOLD);

        if (empty($results)) {
            return '';
        }

        $tableNames = array_map(
            fn (array $hit): string => $hit['payload']['table_name'] ?? 'unknown',
            $results
        );

        Log::info('SchemaSearchService: matched tables', ['tables' => $tableNames]);

        $blocks = array_map(function (array $hit): string {
            $payload = $hit['payload'];
            $tableName = $payload['table_name'] ?? '';
            $columns = $payload['columns'] ?? $payload['schema_text'] ?? '';
            $description = $payload['description'] ?? '';

            $block = "Table: {$tableName}";

            if ($description !== '') {
                $block .= "\nDescription: {$description}";
            }

            if ($columns !== '') {
                $block .= "\nColumns:\n{$columns}";
            }

            return $block;
        }, $results);

        $blocks = array_filter($blocks, fn (string $b): bool => $b !== '');

        return implode("\n\n", $blocks);
    }

    /**
     * @return float[]
     */
    private function embed(string $text): array
    {
        Log::info('SchemaSearchService: generating embedding', ['text' => $text]);

        $response = Embeddings::for([$text])
            ->timeout(30)
            ->generate(Lab::OpenRouter, self::EMBED_MODEL);

        return $response->first();
    }
}
