<?php

namespace App\Ai\Rag;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;

class SchemaIndexer
{
    private const string EMBED_MODEL = 'text-embedding-3-small';

    public function __construct(private QdrantClient $qdrant) {}

    /**
     * Read the JSON data dictionary file, format each table into a searchable chunk,
     * embed each chunk, and upsert into the Qdrant schema collection.
     */
    public function index(): int
    {
        $path = config('ai.data_dictionary_path', 'ai/context/data_dictionary.json');
        $json = Storage::disk('local')->get($path);

        if (! $json) {
            throw new \RuntimeException('Data dictionary file not found at: '.$path);
        }

        $dictionary = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($dictionary)) {
            throw new \RuntimeException('Failed to parse data dictionary JSON: '.json_last_error_msg());
        }

        $chunks = $this->buildChunks($dictionary);

        Log::info('SchemaIndexer: built table chunks from JSON', ['count' => count($chunks)]);

        $this->qdrant->ensureCollection();

        $points = [];

        $index = 1;
        foreach ($chunks as $chunk) {
            $vector = $this->embed($chunk['embed_text']);

            $points[] = [
                'id' => $index++,
                'vector' => $vector,
                'payload' => [
                    'table_name' => $chunk['table_name'],
                    'description' => $chunk['description'],
                    'columns' => $chunk['columns_text'],
                    'schema_text' => $chunk['embed_text'],
                ],
            ];

            Log::info('SchemaIndexer: embedded table', ['table' => $chunk['table_name'], 'id' => $index - 1]);
        }

        $this->qdrant->upsert($points);

        Log::info('SchemaIndexer: upsert complete', ['total_points' => count($points)]);

        return count($points);
    }

    /**
     * Build the structured text chunks for embedding from the JSON dictionary.
     *
     * @param  array<string, array{desc: string, cols: array<int, array<string, mixed>>}>  $dictionary
     * @return array<int, array{table_name: string, description: string, columns_text: string, embed_text: string}>
     */
    private function buildChunks(array $dictionary): array
    {
        $chunks = [];

        foreach ($dictionary as $tableName => $tableData) {
            $description = $tableData['desc'] ?? '';
            $columnsData = $tableData['cols'] ?? [];

            $columnsLines = [];
            foreach ($columnsData as $col) {
                $line = "- {$col['col']} ({$col['type']}";

                if (isset($col['key'])) {
                    $line .= ", {$col['key']}";
                }
                if (isset($col['null']) && $col['null']) {
                    $line .= ', null';
                }
                if (isset($col['default'])) {
                    $line .= ", default: {$col['default']}";
                }

                $line .= ')';

                if (isset($col['comment'])) {
                    $line .= " - {$col['comment']}";
                }

                $columnsLines[] = $line;
            }

            $columnsText = implode("\n", $columnsLines);

            $embedText = "Table: {$tableName}";
            if ($description !== '') {
                $embedText .= "\nDescription: {$description}";
            }
            if ($columnsText !== '') {
                $embedText .= "\nColumns:\n{$columnsText}";
            }

            Log::info('SchemaIndexer: built table chunk', [
                'table_name' => $tableName,
                'description' => $description,
                'columns_text' => $columnsText,
                'embed_text' => $embedText,
            ]);

            $chunks[] = [
                'table_name' => $tableName,
                'description' => $description,
                'columns_text' => $columnsText,
                'embed_text' => $embedText,
            ];
        }

        return $chunks;
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
