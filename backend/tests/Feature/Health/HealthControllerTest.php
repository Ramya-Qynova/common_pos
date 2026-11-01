<?php

test('GET /health is public and reports the database as healthy', function () {
    $response = test()->getJson('/api/v1/health');

    $response->assertStatus(200);
    expect($response->json('status'))->toBe('ok');
    expect($response->json('checks.database'))->toBeTrue();
});
