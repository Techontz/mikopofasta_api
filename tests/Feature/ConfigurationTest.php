<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/*
 * These assertions pin down the platform decisions from backend spec §1 so a
 * later change to config or .env cannot silently undo them.
 */

it('runs on MySQL', function (): void {
    expect(config('database.default'))->toBe('mysql');
});

it('keeps the test schema separate from the development schema', function (): void {
    expect(config('database.connections.mysql.database'))->toBe('mikopofasta_test');
});

it('ships Redis as the cache and queue backend', function (): void {
    // phpunit.xml deliberately overrides these to array/sync for test
    // isolation, so the shipped defaults are asserted from .env.example
    // rather than from the runtime config. Reading env() here instead would
    // silently return null once the config is cached.
    $template = file_get_contents(base_path('.env.example'));

    expect($template)
        ->toContain('CACHE_STORE=redis')
        ->toContain('QUEUE_CONNECTION=redis')
        ->and(config('database.redis.client'))->toBe('predis');
});

it('declares the named queues the platform dispatches to', function (): void {
    expect(config('queue.names'))->toBe([
        'default' => 'default',
        'ledger' => 'ledger',
        'notifications' => 'notifications',
        'reports' => 'reports',
    ]);
});

it('defines the private KYC disk and never exposes it publicly', function (): void {
    $disk = config('filesystems.disks.kyc');

    expect($disk)->not->toBeNull()
        ->and($disk['visibility'])->toBe('private')
        ->and($disk['serve'])->toBeFalse()
        ->and(config('filesystems.links'))->not->toHaveKey(public_path('kyc'));
});

it('restricts CORS to an explicit origin allow-list', function (): void {
    expect(config('cors.allowed_origins'))->not->toContain('*')
        ->and(config('cors.allowed_origins'))->not->toBeEmpty()
        ->and(config('cors.supports_credentials'))->toBeFalse();
});

it('uses immutable dates so period arithmetic cannot mutate shared instances', function (): void {
    expect(Date::now())->toBeInstanceOf(CarbonImmutable::class);
});

it('runs on the business timezone so calendar-date logic matches the business day', function (): void {
    // Penalty accrual, due-date comparison and daily reports all key off
    // calendar dates. Running in UTC would roll today() over at 03:00 EAT.
    expect(config('app.timezone'))->toBe('Africa/Dar_es_Salaam')
        ->and(Date::now()->getTimezone()->getName())->toBe('Africa/Dar_es_Salaam');
});

it('registers the Spatie permission middleware aliases', function (): void {
    $aliases = app(Illuminate\Foundation\Http\Kernel::class)->getRouteMiddleware();

    expect($aliases)->toHaveKeys(['role', 'permission', 'role_or_permission']);
});
