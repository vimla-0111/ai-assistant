<?php

namespace App\Ai\Rag;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;

class SchemaIndexer
{
    private const string EMBED_MODEL = 'text-embedding-3-small';

    public function __construct(private QdrantClient $qdrant) {}

    /**
     * Read the schema context file, chunk by table, embed each chunk,
     * and upsert into the schema Qdrant collection.
     */
    public function index(): int
    {
        $schemaText = Storage::disk('local')->get(config('ai.db_schema_path'));

        if (! $schemaText) {
            throw new \RuntimeException('Database schema context file not found at: '.config('ai.db_schema_path'));
        }

        $chunks = $this->chunkByTable($schemaText);

        Log::info('SchemaIndexer: parsed table chunks', ['count' => count($chunks)]);

        $this->qdrant->ensureCollection();

        $points = [];

        foreach ($chunks as $index => $chunk) {
            $vector = $this->embed($chunk['text']);

            $points[] = [
                'id' => $index + 1,
                'vector' => $vector,
                'payload' => [
                    'table' => $chunk['table'],
                    'text' => $chunk['text'],
                ],
            ];

            Log::info('SchemaIndexer: embedded table', ['table' => $chunk['table'], 'id' => $index + 1]);
        }

        $this->qdrant->upsert($points);

        Log::info('SchemaIndexer: upsert complete', ['total_points' => count($points)]);

        return count($points);
    }

    /**
     * Split the schema text into per-table chunks.
     *
     * Each chunk contains the table's column list and its relationships.
     * Format expected in database_schema.txt:
     *   "- table_name: col1, col2, ..."
     * Relationships section starts with "Relationships:" and uses:
     *   "- table_name.col -> other_table.col"
     *
     * @return array<int, array{table: string, text: string}>
     */
    private function chunkByTable(string $schemaText): array
    {
        [$schemaSection, $relationshipsSection] = $this->splitSections($schemaText);

        $tableLines = $this->parseTableLines($schemaSection);
        $relationships = $this->parseRelationships($relationshipsSection);

        $chunks = [];

        foreach ($tableLines as $table => $columnLine) {
            $text = "Table: {$table}\nColumns: {$columnLine}";

            if (! empty($relationships[$table])) {
                $text .= "\nRelationships:\n".implode("\n", $relationships[$table]);
            }

            $chunks[] = [
                'table' => $table,
                'text' => $text,
            ];
        }

        return $chunks;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitSections(string $text): array
    {
        $relPos = strpos($text, 'Relationships:');

        if ($relPos === false) {
            return [$text, ''];
        }

        return [
            substr($text, 0, $relPos),
            substr($text, $relPos),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseTableLines(string $schemaSection): array
    {
        $tables = [];

        foreach (explode("\n", $schemaSection) as $line) {
            $line = trim($line);

            // Match lines like: "- users: id, name, email, ..."
            if (Str::startsWith($line, '- ') && Str::contains($line, ':')) {
                $withoutDash = Str::after($line, '- ');
                $table = trim(Str::before($withoutDash, ':'));
                $columns = trim(Str::after($withoutDash, ':'));

                if ($table !== '' && $columns !== '') {
                    $tables[$table] = $columns;
                }
            }
        }

        return $tables;
    }

    /**
     * @return array<string, string[]>
     */
    private function parseRelationships(string $relationshipsSection): array
    {
        $byTable = [];

        foreach (explode("\n", $relationshipsSection) as $line) {
            $line = trim($line);

            // Match lines like: "- users.department_id -> departments.id"
            if (Str::startsWith($line, '- ') && Str::contains($line, '->')) {
                $rel = Str::after($line, '- ');
                $leftSide = trim(Str::before($rel, '->'));
                $table = trim(Str::before($leftSide, '.'));

                if ($table !== '') {
                    $byTable[$table][] = trim($rel);
                }
            }
        }

        return $byTable;
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
