<?php

namespace App\Providers;

use App\Ai\Rag\QdrantClient;
use App\Ai\Rag\SchemaIndexer;
use App\Ai\Rag\SchemaSearchService;
use App\Ai\Tools\ContextTool;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;

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

        $this->app->bind(ContextTool::class, fn ($app) => new ContextTool(
            qdrantResume: new QdrantClient(config('ai.qdrant.collection')),
            schemaSearch: $app->make(SchemaSearchService::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(InvokingTool::class, function ($event) {
            $toolName = class_basename($event->tool);
            Log::info("AI is calling tool: {$toolName}", [
                'arguments' => $event->arguments,
                'agent' => class_basename($event->agent),
            ]);
        });

        Event::listen(ToolInvoked::class, function ($event) {
            $toolName = class_basename($event->tool);
            $result = is_string($event->result) ? $event->result : json_encode($event->result);

            // Truncate result if it's too long so it doesn't flood the logs
            if (strlen($result) > 1000) {
                $result = substr($result, 0, 1000).'... (truncated)';
            }

            Log::info("AI finished tool: {$toolName}", [
                'result' => $result,
            ]);
        });
    }
}
