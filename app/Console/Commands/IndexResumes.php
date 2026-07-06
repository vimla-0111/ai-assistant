<?php

namespace App\Console\Commands;

use App\Ai\Rag\QdrantClient;
use App\Ai\Rag\ResumeIndexer;
use Illuminate\Console\Command;

class IndexResumes extends Command
{
    protected $signature = 'resumes:index
                            {--force : Re-index already indexed resumes}
                            {--candidate= : Index a single candidate by ID}';

    protected $description = 'Index candidate resumes from iresource_db into Qdrant for RAG search';

    public function handle(ResumeIndexer $indexer, QdrantClient $qdrant): int
    {
        $this->info('Checking Qdrant connection...');

        try {
            $qdrant->ensureCollection();
            $this->info('Qdrant collection ready.');
        } catch (\Throwable $e) {
            $this->error('Cannot connect to Qdrant: '.$e->getMessage());

            return self::FAILURE;
        }

        $force       = $this->option('force');
        $candidateId = $this->option('candidate') ? (int) $this->option('candidate') : null;

        if ($force) {
            $this->warn('Force mode: re-indexing all resumes.');
        }

        if ($candidateId) {
            $this->info("Indexing single candidate ID: {$candidateId}");
        }

        $results = $indexer->indexAll(force: $force, candidateId: $candidateId);

        $this->table(
            ['Indexed', 'Skipped', 'Failed'],
            [[$results['indexed'], $results['skipped'], $results['failed']]]
        );

        $info  = $qdrant->collectionInfo();
        $count = $info['points_count'] ?? 'unknown';
        $this->info("Qdrant collection now has {$count} points.");

        return self::SUCCESS;
    }
}
