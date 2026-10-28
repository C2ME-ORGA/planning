<?php

use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TemporaryDatabase;

function timesheetRow(int $employeeId, string $start = '2026-11-02', string $end = '2026-11-08'): array
{
    return [
        'employee_id' => $employeeId,
        'status' => 'brouillon',
        'period_start' => $start,
        'period_end' => $end,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

it('creates the timesheet tables with the expected columns, indexes and foreign keys', function (): void {
    expect(Schema::hasTable('timesheets'))->toBeTrue()
        ->and(Schema::hasTable('timesheet_segments'))->toBeTrue()
        ->and(Schema::getColumnListing('timesheets'))->toBe([
            'id',
            'employee_id',
            'status',
            'period_start',
            'period_end',
            'created_at',
            'updated_at',
        ])
        ->and(Schema::getColumnListing('timesheet_segments'))->toBe([
            'id',
            'timesheet_id',
            'category',
            'start_at',
            'end_at',
            'start_offset_minutes',
            'end_offset_minutes',
            'created_at',
            'updated_at',
        ]);

    $timesheetIndexes = collect(Schema::getIndexes('timesheets'))->pluck('name');
    $segmentIndexes = collect(Schema::getIndexes('timesheet_segments'))->pluck('name');

    expect($timesheetIndexes)->toContain('timesheets_employee_period_unique')
        ->and($timesheetIndexes)->toContain('timesheets_status_index')
        ->and($segmentIndexes)->toContain('timesheet_segments_timesheet_id_start_at_index')
        ->and($segmentIndexes)->toContain('timesheet_segments_start_at_end_at_index');

    $timesheetForeignKeys = collect(Schema::getForeignKeys('timesheets'));
    $segmentForeignKeys = collect(Schema::getForeignKeys('timesheet_segments'));

    $employeeForeignKey = $timesheetForeignKeys->first(
        static fn (array $foreignKey): bool => $foreignKey['columns'] === ['employee_id'],
    );
    $timesheetForeignKey = $segmentForeignKeys->first(
        static fn (array $foreignKey): bool => $foreignKey['columns'] === ['timesheet_id'],
    );

    expect($employeeForeignKey['foreign_table'] ?? null)->toBe('employees')
        ->and(strtolower((string) ($employeeForeignKey['on_delete'] ?? '')))->toBe('restrict')
        ->and($timesheetForeignKey['foreign_table'] ?? null)->toBe('timesheets')
        ->and(strtolower((string) ($timesheetForeignKey['on_delete'] ?? '')))->toBe('restrict');
});

it('enforces the status, week, category and ordering constraints', function (): void {
    $employee = Employee::factory()->create();

    $invalidStatus = timesheetRow($employee->id);
    $invalidStatus['status'] = 'inconnu';

    expect(fn () => DB::table('timesheets')->insert($invalidStatus))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('timesheets')->insert(timesheetRow($employee->id, '2026-11-03', '2026-11-09')))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('timesheets')->insert(timesheetRow($employee->id, '2026-11-02', '2026-11-07')))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('timesheets')->insert(timesheetRow($employee->id, '2026-11-09', '2026-11-02')))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('timesheets')->insert(timesheetRow($employee->id, '2026-11-02', '2026-11-10')))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('timesheets')->insert(timesheetRow($employee->id, '2026-11-08', '2026-11-14')))
        ->toThrow(QueryException::class);

    $crossYearId = DB::table('timesheets')->insertGetId(
        timesheetRow($employee->id, '2026-12-28', '2027-01-03'),
    );

    expect($crossYearId)->toBeInt();

    $timesheetId = DB::table('timesheets')->insertGetId(timesheetRow($employee->id));

    expect(fn () => DB::table('timesheet_segments')->insert([
        'timesheet_id' => $timesheetId,
        'category' => 'inconnue',
        'start_at' => '2026-11-02 07:00:00',
        'end_at' => '2026-11-02 08:00:00',
        'start_offset_minutes' => 60,
        'end_offset_minutes' => 60,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('timesheet_segments')->insert([
        'timesheet_id' => $timesheetId,
        'category' => 'pause',
        'start_at' => '2026-11-02 08:00:00',
        'end_at' => '2026-11-02 08:00:00',
        'start_offset_minutes' => 60,
        'end_offset_minutes' => 60,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces the unique employee period and the foreign keys', function (): void {
    $employee = Employee::factory()->create();

    DB::table('timesheets')->insert(timesheetRow($employee->id));

    expect(fn () => DB::table('timesheets')->insert(timesheetRow($employee->id)))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('timesheet_segments')->insert([
        'timesheet_id' => 999999,
        'category' => 'trajet',
        'start_at' => '2026-11-02 07:00:00',
        'end_at' => '2026-11-02 08:00:00',
        'start_offset_minutes' => 60,
        'end_offset_minutes' => 60,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('employees')->where('id', $employee->id)->delete())
        ->toThrow(QueryException::class);
});

it('reverses the timesheet migrations on an isolated database', function (): void {
    TemporaryDatabase::run(function (string $database): void {
        TemporaryDatabase::migrate($database);

        $schema = Schema::connection('migration_test');

        expect($schema->hasTable('timesheets'))->toBeTrue()
            ->and($schema->hasTable('timesheet_segments'))->toBeTrue();

        $exitCode = Artisan::call('migrate:rollback', [
            '--database' => 'migration_test',
            '--path' => [
                'database/migrations/2026_10_28_000001_create_timesheets_table.php',
                'database/migrations/2026_10_28_000002_create_timesheet_segments_table.php',
            ],
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($schema->hasTable('timesheet_segments'))->toBeFalse()
            ->and($schema->hasTable('timesheets'))->toBeFalse();

        TemporaryDatabase::migrate($database);

        expect($schema->hasTable('timesheets'))->toBeTrue()
            ->and($schema->hasTable('timesheet_segments'))->toBeTrue();
    });
});
