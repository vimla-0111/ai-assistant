<?php

use App\Ai\Agents\ProjectAssistant;
use App\Models\User;

test('authenticated users can prompt the project assistant from the agent route', function () {
    $user = User::factory()->create();

    ProjectAssistant::fake([
        'The schema includes the users table.',
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson(route('agent.prompt'), [
            'prompt' => 'Which tables are available?',
        ]);

    $response
        ->assertOk()
        ->assertJson([
            'answer' => 'The schema includes the users table.',
        ]);

    ProjectAssistant::assertPrompted('Which tables are available?');
});

test('agent route requires a prompt payload', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->postJson(route('agent.prompt'), []);

    $response->assertInvalid(['prompt']);
});
