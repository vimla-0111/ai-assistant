<?php

namespace App\Ai\Rag;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;

class SchemaSearchService
{
    private const string EMBED_MODEL = 'text-embedding-3-small';

    private int $topK;

    public function __construct(private QdrantClient $qdrant)
    {
        $this->topK = (int) config('ai.qdrant.schema_top_k', 5);
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
            $results = $this->qdrant->search($vector, $limit);

            if (empty($results)) {
                Log::warning('SchemaSearchService: no schema matches found', ['prompt' => $prompt]);

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
                    $block .= "\nColumns: {$columns}";
                }

                return $block;
            }, $results);

            $blocks = array_filter($blocks, fn (string $b): bool => $b !== '');

            return implode("\n\n", $blocks);
        } catch (\Throwable $e) {
            Log::error('SchemaSearchService: search failed', [
                'error' => $e->getMessage(),
                'prompt' => $prompt,
            ]);

            return '';
        }
    }

    /**
     * @return float[]
     */
    private function embed(string $text): array
    {
        $response = Embeddings::for([$text])
            ->timeout(30)
            ->generate(Lab::OpenRouter, self::EMBED_MODEL);

        return $response->first();
    }
}
