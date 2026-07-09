<?php

namespace App\Ai\Rag;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QdrantClient
{
    private string $baseUrl;

    private string $collection;

    private int $dimensions;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('ai.qdrant.host'), '/').':'.config('ai.qdrant.port');
        $this->collection = config('ai.qdrant.collection');
        $this->dimensions = config('ai.qdrant.dimensions');
    }

    /**
     * Create the collection if it does not already exist.
     */
    public function ensureCollection(): void
    {
        $response = Http::get("{$this->baseUrl}/collections/{$this->collection}");

        if ($response->successful()) {
            Log::info('QdrantClient: collection already exists', [
                'collection' => $this->collection,
            ]);

            return;
        }

        Log::info('QdrantClient: creating collection', [
            'collection' => $this->collection,
            'dimensions' => $this->dimensions,
            'distance' => 'Cosine',
        ]);

        Http::put("{$this->baseUrl}/collections/{$this->collection}", [
            'vectors' => [
                'size' => $this->dimensions,
                'distance' => 'Cosine',
            ],
        ])->throw();

        Log::info('QdrantClient: collection created', ['collection' => $this->collection]);
    }

    /**
     * Upsert a batch of points into the collection.
     *
     * @param  array<int, array{id: int, vector: float[], payload: array}>  $points
     */
    public function upsert(array $points): void
    {
        Log::info('QdrantClient: upserting points', [
            'collection' => $this->collection,
            'count' => count($points),
            'point_ids' => array_column($points, 'id'),
        ]);

        Http::put("{$this->baseUrl}/collections/{$this->collection}/points", [
            'points' => $points,
        ])->throw();

        Log::info('QdrantClient: upsert successful', [
            'collection' => $this->collection,
            'count' => count($points),
        ]);
    }

    /**
     * Search for the nearest vectors to the given query vector.
     *
     * @param  float[]  $vector
     * @return array<int, array{id: int, score: float, payload: array}>
     */
    public function search(array $vector, int $limit = 5, array $filter = []): array
    {
        Log::info('QdrantClient: searching', [
            'collection' => $this->collection,
            'limit' => $limit,
            'filter_active' => ! empty($filter),
        ]);

        $body = [
            'vector' => $vector,
            'limit' => $limit,
            'with_payload' => true,
        ];

        if (! empty($filter)) {
            $body['filter'] = $filter;
        }

        $results = Http::post(
            "{$this->baseUrl}/collections/{$this->collection}/points/search",
            $body
        )->throw()->json('result', []);

        Log::info('QdrantClient: search results', [
            'collection' => $this->collection,
            'hits' => count($results),
            'top_score' => ! empty($results) ? round($results[0]['score'], 4) : null,
        ]);

        return $results;
    }

    /**
     * Delete all points for a given candidate.
     */
    public function deleteByCandidate(int $candidateId): void
    {
        Log::info('QdrantClient: deleting points for candidate', [
            'collection' => $this->collection,
            'candidate_id' => $candidateId,
        ]);

        Http::post("{$this->baseUrl}/collections/{$this->collection}/points/delete", [
            'filter' => [
                'must' => [[
                    'key' => 'candidate_id',
                    'match' => ['value' => $candidateId],
                ]],
            ],
        ])->throw();

        Log::info('QdrantClient: delete successful', ['candidate_id' => $candidateId]);
    }

    /**
     * Get collection info (point count, vector count etc.).
     */
    public function collectionInfo(): array
    {
        return Http::get("{$this->baseUrl}/collections/{$this->collection}")
            ->throw()
            ->json('result', []);
    }
}
