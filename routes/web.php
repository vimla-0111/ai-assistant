<?php

use App\Ai\Agents\ProjectAssistant;
use App\Http\Controllers\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Document;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/agent', function (Request $request) {
        $prompt = trim((string) $request->query('prompt', ''));

        $response = ProjectAssistant::make(user: $request->user())->prompt(
            // 'Give me the name of user registered with email axar.test.120226@gmail.com',
            'Give me the all active departments and its users detail',
            provider: Lab::OpenRouter,
            model: 'nvidia/nemotron-3-super-120b-a12b:free',
            timeout: 120,
            attachments: [
                // Document::fromStorage(config('ai.db_schema_path'), 'local'),
            ],
        );

        Log::info('agent conversation id is: ' . $response->conversationId);
        // dd($response);
        return $response;

        return response()->json([
            'answer' => $response->toArray(),
        ]);
    })->name('agent');
});

require __DIR__ . '/auth.php';
