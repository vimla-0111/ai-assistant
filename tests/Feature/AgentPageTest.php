<?php

use App\Models\User;

test('authenticated users can render the agent chat page', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('agent'));

    $response
        ->assertOk()
        ->assertSee('Project Assistant')
        ->assertSee('Send Message');
});

test('guests are redirected away from the agent chat page', function () {
    $response = $this->get(route('agent'));

    $response->assertRedirect(route('login'));
});
