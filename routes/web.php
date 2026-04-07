<?php

use App\Ai\Agents\ProjectAssistant;
use App\Http\Controllers\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Enums\Lab;

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

        // if ($prompt === '') {
        //     return response()->json([
        //         'message' => 'Pass a prompt using the ?prompt= query string.',
        //     ], 422);
        // }

        $response = ProjectAssistant::make(user: $request->user())->prompt(
            'Give me the name of user registered with email axar.test.120226@gmail.com'
            // provider: Lab::OpenRouter,
            // model: 'nvidia/nemotron-3-super-120b-a12b:free',
            // timeout: 120,
        );

        return response()->json([
            'answer' => $response->toArray(),
        ]);
    })->name('agent');
});

require __DIR__.'/auth.php';
