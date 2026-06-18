<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // A real (empty) jobs table so size() / oldest-job probing runs against the
    // database driver rather than throwing and falling back to 'n/a'.
    Schema::create('jobs', function ($table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

afterEach(function () {
    Schema::dropIfExists('jobs');
});

function configureQueueDoctor(string $defaultConnection = 'database'): void
{
    config()->set('queue.default', $defaultConnection);
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'connection' => null,
    ]);
    config()->set('cache.default', 'array');
    config()->set('session.driver', 'array');
}

it('reports a healthy queue when the default connection has no backlog', function () {
    configureQueueDoctor();

    $this->artisan('queue:doctor')
        ->expectsOutputToContain('Queue healthy')
        ->assertExitCode(0);
});

it('fails with STALLED when the default connection has an old pending job', function () {
    configureQueueDoctor();

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->subHours(5)->getTimestamp(),
        'created_at' => now()->subHours(5)->getTimestamp(),
    ]);

    $this->artisan('queue:doctor', ['--max-age' => 3600])
        ->expectsOutputToContain('STALLED')
        ->assertExitCode(1);
});

it('warns but succeeds for a fresh backlog under max-age', function () {
    configureQueueDoctor();

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->getTimestamp(),
        'created_at' => now()->getTimestamp(),
    ]);

    $this->artisan('queue:doctor', ['--max-age' => 3600])
        ->assertExitCode(0);
});

it('fails hard when the web-tier cache store is unreachable', function () {
    configureQueueDoctor();

    // An undefined store name makes Cache::store() throw — the same code path a
    // downed Redis takes ("SSL: Connection reset by peer"), which must surface as
    // a WEB-TIER STORE DOWN failure regardless of queue health.
    config()->set('cache.default', 'definitely-not-a-real-store');

    $this->artisan('queue:doctor')
        ->expectsOutputToContain('WEB-TIER STORE DOWN')
        ->assertExitCode(1);
});
