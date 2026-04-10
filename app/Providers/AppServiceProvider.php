<?php

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\InvokingTool;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        Event::listen(InvokingTool::class, function ($event) {
            Log::info("AI is calling tool: ". json_encode($event->tool), [
                'arguments' => $event->arguments,
                'agent' => get_class($event->agent)
            ]);
        });
    }
}
