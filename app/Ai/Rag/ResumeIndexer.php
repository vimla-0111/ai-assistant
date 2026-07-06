<?php

namespace App\Ai\Rag;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

class ResumeIndexer
{
    private const RESUME_DIR    = '/var/www/html/iresource/public/uploads/resume/';

    private const CHUNK_SIZE    = 500;

    private const CHUNK_OVERLAP = 80;

    private const EMBED_BATCH   = 5;

    private const EMBED_MODEL   = 'text-embedding-3-small';

    public function __construct(private QdrantClient $qdrant) {}

    /**
     * Index all candidates that have a resume file on disk.
     * Pass $candidateId to re-index a single candidate.
     */
    public function indexAll(bool $force = false, ?int $candidateId = null): array
    {
        Log::info('ResumeIndexer: starting', [
            'force'        => $force,
            'candidate_id' => $candidateId ?? 'all',
        ]);

        $candidates = $this->fetchCandidates($candidateId);
        $results    = ['indexed' => 0, 'skipped' => 0, 'failed' => 0];

        Log::info('ResumeIndexer: candidates fetched', [
            'total' => $candidates->count(),
        ]);

        $this->qdrant->ensureCollection();

        foreach ($candidates as $candidate) {
            $path = self::RESUME_DIR . $candidate->resume;

            if (! file_exists($path)) {
                Log::warning('ResumeIndexer: resume file not found — skipping', [
                    'candidate_id'   => $candidate->id,
                    'candidate_name' => $candidate->name,
                    'expected_path'  => $path,
                ]);
                $results['skipped']++;
                continue;
            }

            if (! $force && $this->isAlreadyIndexed($candidate->id)) {
                Log::info('ResumeIndexer: already indexed — skipping', [
                    'candidate_id'   => $candidate->id,
                    'candidate_name' => $candidate->name,
                ]);
                $results['skipped']++;
                continue;
            }

            try {
                $this->indexCandidate($candidate, $path);
                $results['indexed']++;
            } catch (\Throwable $e) {
                Log::error('ResumeIndexer: indexing failed', [
                    'candidate_id'   => $candidate->id,
                    'candidate_name' => $candidate->name,
                    'error'          => $e->getMessage(),
                    'trace'          => $e->getTraceAsString(),
                ]);
                $results['failed']++;
            }
        }

        Log::info('ResumeIndexer: completed', $results);

        return $results;
    }

    /**
     * Index a single candidate's resume into Qdrant.
     */
    private function indexCandidate(object $candidate, string $filePath): void
    {
        Log::info('ResumeIndexer: parsing PDF', [
            'candidate_id' => $candidate->id,
            'file'         => basename($filePath),
        ]);

        $parser = new Parser();
        $text   = trim($parser->parseFile($filePath)->getText());
        unset($parser);

        if (blank($text)) {
            throw new \RuntimeException("Empty PDF text for candidate {$candidate->id}");
        }

        Log::info('ResumeIndexer: PDF parsed', [
            'candidate_id' => $candidate->id,
            'text_length'  => strlen($text),
        ]);

        $chunks = $this->chunk($text);

        Log::info('ResumeIndexer: text chunked', [
            'candidate_id' => $candidate->id,
            'chunk_count'  => count($chunks),
            'chunk_size'   => self::CHUNK_SIZE,
            'overlap'      => self::CHUNK_OVERLAP,
        ]);

        // Remove stale vectors before re-indexing
        try {
            $this->qdrant->deleteByCandidate($candidate->id);
            Log::info('ResumeIndexer: old vectors deleted', ['candidate_id' => $candidate->id]);
        } catch (\Throwable) {
            // Safe to ignore on first run — collection may be empty
        }

        $batches     = array_chunk($chunks, self::EMBED_BATCH);
        $totalPoints = 0;

        foreach ($batches as $batchIndex => $batch) {
            Log::info('ResumeIndexer: embedding batch', [
                'candidate_id' => $candidate->id,
                'batch'        => $batchIndex + 1 . '/' . count($batches),
                'texts'        => count($batch),
                'model'        => self::EMBED_MODEL,
            ]);

            $vectors = $this->embed($batch);
            $points  = [];

            foreach ($batch as $i => $chunkText) {
                $globalIndex = $batchIndex * self::EMBED_BATCH + $i;

                $points[] = [
                    'id'      => ($candidate->id * 10000) + $globalIndex,
                    'vector'  => $vectors[$i],
                    'payload' => [
                        'candidate_id'   => (int) $candidate->id,
                        'candidate_name' => $candidate->name,
                        'resume_file'    => $candidate->resume,
                        'chunk_index'    => $globalIndex,
                        'chunk_text'     => $chunkText,
                    ],
                ];
            }

            $this->qdrant->upsert($points);
            $totalPoints += count($points);

            Log::info('ResumeIndexer: batch upserted to Qdrant', [
                'candidate_id' => $candidate->id,
                'batch'        => $batchIndex + 1 . '/' . count($batches),
                'points'       => count($points),
            ]);
        }

        Log::info('ResumeIndexer: candidate indexed successfully', [
            'candidate_id'   => $candidate->id,
            'candidate_name' => $candidate->name,
            'total_chunks'   => count($chunks),
            'total_points'   => $totalPoints,
        ]);
    }

    /**
     * Check if a candidate already has vectors in Qdrant.
     */
    private function isAlreadyIndexed(int $candidateId): bool
    {
        try {
            $results = $this->qdrant->search(
                vector: array_fill(0, config('ai.qdrant.dimensions', 1536), 0.0),
                limit: 1,
                filter: [
                    'must' => [[
                        'key'   => 'candidate_id',
                        'match' => ['value' => $candidateId],
                    ]],
                ]
            );

            return ! empty($results);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Fetch interviewee candidates with a resume value from iresource_db.
     */
    private function fetchCandidates(?int $candidateId): \Illuminate\Support\Collection
    {
        $query = DB::connection('iresource_db')
            ->table('users')
            ->where('role', 'interviewee')
            ->whereNotNull('resume')
            ->where('resume', '!=', '');

        if ($candidateId) {
            $query->where('id', $candidateId);
        }

        return $query->get(['id', 'name', 'resume']);
    }

    /**
     * Generate embeddings via OpenRouter API.
     *
     * @param  string[]  $inputs
     * @return array<int, float[]>
     */
    private function embed(array $inputs): array
    {
        $response = Http::withToken(config('ai.providers.openrouter.key'))
            ->timeout(60)
            ->post('https://openrouter.ai/api/v1/embeddings', [
                'model' => self::EMBED_MODEL,
                'input' => $inputs,
            ])->throw();

        return array_column($response->json('data'), 'embedding');
    }

    /**
     * Split text into overlapping chunks.
     *
     * @return string[]
     */
    private function chunk(string $text): array
    {
        $text   = preg_replace('/\s+/', ' ', $text);
        $length = strlen($text);
        $chunks = [];
        $start  = 0;

        while ($start < $length) {
            $chunk = substr($text, $start, self::CHUNK_SIZE);

            if ($start + self::CHUNK_SIZE < $length) {
                $breakAt = max(
                    (int) strrpos($chunk, '. '),
                    (int) strrpos($chunk, ', '),
                );

                if ($breakAt > self::CHUNK_SIZE * 0.5) {
                    $chunk = substr($chunk, 0, $breakAt + 1);
                }
            }

            $chunks[] = trim($chunk);
            $advance  = strlen($chunk) - self::CHUNK_OVERLAP;

            if ($advance <= 0) {
                break;
            }

            $start += $advance;
        }

        return array_values(array_filter($chunks, fn (string $c) => strlen($c) > 20));
    }
}
