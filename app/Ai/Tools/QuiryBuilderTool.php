<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class QuiryBuilderTool implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Use this tool to fetch data from the database to answer user questions about application records, counts, or specific rows. '
            .'Execute Laravel Query Builder PHP code against the iresource_db database. '
            .'DO NOT write raw SQL (like "SELECT * FROM..."). You MUST write valid PHP code starting with DB::connection("iresource_db")->table(...). '
            .'STRICTLY FORBIDDEN: Do not write queries that modify data (e.g., insert, update, delete, drop, truncate). Only SELECT queries and aggregate methods (count, sum, avg, get, first, pluck, value) are allowed. '
            .'Example: DB::connection("iresource_db")->table("users")->where("active", 2)->get();';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $query = $request->string('query');
        try {
            $result = eval("return {$query};");

            return is_string($result) ? $result : json_encode($result);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required(),
        ];
    }
}
