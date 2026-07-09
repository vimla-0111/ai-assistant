<?php

namespace App\Providers;

use App\Ai\Rag\QdrantClient;
use App\Ai\Rag\SchemaIndexer;
use App\Ai\Rag\SchemaSearchService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\InvokingTool;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind('qdrant.schema', fn () => new QdrantClient(
            config('ai.qdrant.schema_collection')
        ));

        $this->app->bind(SchemaIndexer::class, fn ($app) => new SchemaIndexer(
            $app->make('qdrant.schema')
        ));

        $this->app->bind(SchemaSearchService::class, fn ($app) => new SchemaSearchService(
            $app->make('qdrant.schema')
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        Event::listen(InvokingTool::class, function ($event) {
            Log::info('AI is calling tool: '.json_encode($event->tool), [
                'arguments' => $event->arguments,
                'agent' => get_class($event->agent),
            ]);
        });
    }
}
