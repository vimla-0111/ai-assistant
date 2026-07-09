<?php

namespace App\Console\Commands;

use App\Ai\Rag\SchemaIndexer;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'ai:index-schema', description: 'Embed and index the database schema into the Qdrant table_schemas collection for similarity search.')]
class IndexSchemaCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SchemaIndexer $indexer): int
    {
        $this->info('Indexing database schema into Qdrant...');

        try {
            $count = $indexer->index();

            $this->info("Done. {$count} table(s) indexed successfully.");
        } catch (\Throwable $e) {
            $this->error('Failed to index schema: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
