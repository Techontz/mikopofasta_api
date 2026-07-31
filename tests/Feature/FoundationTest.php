<?php

declare(strict_types=1);

/*
 * Phase 1 smoke tests: the application boots, the API is mounted where the
 * frontend expects it, and unauthenticated access is refused as JSON rather
 * than an HTML redirect.
 */

it('exposes a JSON service identity at the root', function (): void {
    $this->getJson('/')
        ->assertOk()
        ->assertJsonPath('data.api_version', 'v1')
        ->assertJsonPath('data.service', config('app.name'));
});

it('answers the liveness probe', function (): void {
    $this->get('/up')->assertOk();
});

it('exposes the versioned health endpoint', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.api_version', 'v1')
        ->assertJsonPath('data.environment', 'testing');
});

it('rejects unauthenticated API access with JSON, not a redirect', function (): void {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('error_code', 'UNAUTHENTICATED')
        ->assertHeader('content-type', 'application/json');
});

it('forces a JSON response even when the client sends no Accept header', function (): void {
    $this->get('/api/v1/auth/me', ['Accept' => 'text/html'])
        ->assertUnauthorized()
        ->assertHeader('content-type', 'application/json');
});

it('does not expose an unversioned API namespace', function (): void {
    $this->getJson('/api/health')->assertNotFound();
});
