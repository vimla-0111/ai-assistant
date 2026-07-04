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
        return 'Execute Laravel query builder expressions against the iresource_db database and return results as JSON. '
            .'Only SELECT queries and aggregate methods (count, sum, avg, get, first, pluck, value) are allowed. '
            .'Always use DB::connection("iresource_db") for all queries. '
            .'IMPORTANT - users table "active" column values: 1 = inactive, 2 = active. '
            .'Always use ->where("active", 2) when filtering for active users/candidates.';
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
