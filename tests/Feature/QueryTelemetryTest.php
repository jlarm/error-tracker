<?php

use Armp\ErrorTracker\Telemetry\ExecutionContext;
use Armp\ErrorTracker\Telemetry\QueryRecorder;
use Armp\ErrorTracker\Telemetry\TelemetryBuffer;
use Armp\ErrorTracker\Tests\Fixtures\ArrayTelemetryBuffer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config([
        'error-tracker.telemetry.enabled' => true,
        'error-tracker.telemetry.slow_query_ms' => 100,
    ]);

    $this->buffer = new ArrayTelemetryBuffer;
    $this->app->instance(TelemetryBuffer::class, $this->buffer);
    $this->app->forgetInstance(QueryRecorder::class);
    $this->app->forgetInstance(ExecutionContext::class);
});

/**
 * Fire a query event the way the database layer does.
 */
function runQuery(string $sql, float $milliseconds): void
{
    Event::dispatch(new QueryExecuted($sql, [], $milliseconds, DB::connection()));
}

/**
 * @return list<array<string, mixed>>
 */
function queryEntries(): array
{
    return array_values(array_filter(test()->buffer->entries, fn (array $entry) => $entry['type'] === 'query'));
}

test('records queries at or over the threshold, with their shape only', function () {
    runQuery("select * from users where email = 'ada@example.test' and id in (1, 2, 3) limit 10", 250.5);
    runQuery('select * from users where id = ?', 20);

    expect(queryEntries())->toHaveCount(1)
        ->and(queryEntries()[0])->toMatchArray([
            'sql' => 'select * from users where email = ? and id in (?) limit ?',
            'connection' => DB::connection()->getName(),
            'duration_ms' => 250.5,
            'origin' => null,
        ]);
});

test('normalizes literals but keeps names that contain digits', function (string $sql, string $normalized) {
    expect(QueryRecorder::normalize($sql))->toBe($normalized);
})->with([
    ['select * from "table2" where "col_1" = 5', 'select * from "table2" where "col_1" = ?'],
    ["update t set note = 'it''s', score = -1.5 where id = ?", 'update t set note = ?, score = ? where id = ?'],
    ["select *\n  from   t where id in (?, ?,?)", 'select * from t where id in (?)'],
]);

test('labels a query with the route that ran it', function () {
    Route::get('/orders/{order}', function () {
        runQuery('select * from orders where id = ?', 150);

        return 'ok';
    });

    $this->get('/orders/7')->assertOk();

    expect(queryEntries()[0]['origin'])->toBe('GET /orders/{order}');
});

test('caps slow queries per context', function () {
    $this->app->make(ExecutionContext::class)->enter('App\\Jobs\\Import');

    foreach (range(1, 60) as $i) {
        runQuery('select * from t', 200);
    }

    expect(queryEntries())->toHaveCount(50)
        ->and(queryEntries()[0]['origin'])->toBe('App\\Jobs\\Import');

    $this->app->make(ExecutionContext::class)->enter('App\\Jobs\\Other');
    runQuery('select * from t', 200);

    expect(queryEntries())->toHaveCount(51);
});

test('records nothing when telemetry or the threshold is off', function (array $config) {
    config($config);

    runQuery('select * from t', 5000);

    expect(queryEntries())->toBeEmpty();
})->with([
    'telemetry off' => [['error-tracker.telemetry.enabled' => false]],
    'threshold 0' => [['error-tracker.telemetry.slow_query_ms' => 0]],
]);
