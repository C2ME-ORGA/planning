<?php

namespace App\Services\Timesheets;

use App\Enums\AuditAction;
use App\Enums\TimeCategory;
use App\Models\Timesheet;
use App\Models\TimesheetSegment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\TimesheetMinutePrecision;
use App\Support\TimesheetWeekBounds;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Écriture des segments d'un relevé en brouillon.
 *
 * Toute opération est autorisée par la policy du relevé (`update`), puis
 * sérialisée par le verrou de la ligne `timesheets` concernée. Le statut, le
 * propriétaire et l'appartenance du segment sont toujours relus sous verrou :
 * un objet chargé avant un changement de statut ne peut pas servir d'autorité.
 *
 * Les bornes sont exprimées dans le fuseau `Europe/Paris` puis comparées en
 * UTC par `TimesheetWeekBounds`. Un segment doit commencer et finir dans la
 * semaine du relevé, du lundi 00:00 au dimanche 23:59 inclus ; il peut
 * traverser minuit mais ne peut pas franchir la limite de la semaine. Aucun
 * arrondi n'est effectué.
 *
 * Chaque écriture produit un événement `timesheet.updated` attribué, dans la
 * même transaction que la modification. La suppression est également refusée
 * au niveau du modèle hors brouillon, en défense en profondeur.
 */
class TimesheetSegmentService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function add(Timesheet $timesheet, array $data, User $actor): TimesheetSegment
    {
        Gate::forUser($actor)->authorize('update', $timesheet);

        $values = $this->normalize($data);

        return DB::transaction(function () use ($timesheet, $values, $actor): TimesheetSegment {
            $locked = $this->lockEditableTimesheet($timesheet, $actor);

            TimesheetWeekBounds::assertContains($locked, $values['start_at'], $values['end_at']);

            $segment = new TimesheetSegment([
                'timesheet_id' => $locked->getKey(),
                'category' => $values['category'],
                'start_at' => $values['start_at'],
                'end_at' => $values['end_at'],
            ]);
            $segment->save();

            $this->audit->record(
                AuditAction::TimesheetUpdated,
                $locked,
                [
                    'operation' => 'segment_added',
                    'segment_id' => (int) $segment->getKey(),
                ] + $this->auditableValues($segment),
                null,
                $actor,
            );

            return $segment;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TimesheetSegment $segment, array $data, User $actor): TimesheetSegment
    {
        $values = $this->normalize($data);
        $parent = $segment->timesheet()->first();

        if (! $parent instanceof Timesheet) {
            throw ValidationException::withMessages([
                'segment' => 'Ce segment ne peut pas être modifié.',
            ]);
        }

        Gate::forUser($actor)->authorize('update', $parent);

        return DB::transaction(function () use ($segment, $values, $actor): TimesheetSegment {
            // Ordre de verrouillage imposé : relevé parent, puis segment.
            $timesheet = $this->lockEditableTimesheetById((int) $segment->timesheet_id, $actor);

            $locked = TimesheetSegment::query()
                ->whereKey($segment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->timesheet_id !== (int) $timesheet->getKey()) {
                throw ValidationException::withMessages([
                    'segment' => 'Ce segment ne peut pas être modifié.',
                ]);
            }

            TimesheetWeekBounds::assertContains($timesheet, $values['start_at'], $values['end_at']);

            $before = $this->auditableValues($locked);

            $locked->fill([
                'category' => $values['category'],
                'start_at' => $values['start_at'],
                'end_at' => $values['end_at'],
            ]);
            $changed = array_keys($locked->getDirty());
            $locked->save();

            $this->audit->record(
                AuditAction::TimesheetUpdated,
                $timesheet,
                [
                    'operation' => 'segment_updated',
                    'segment_id' => (int) $locked->getKey(),
                    'changed_fields' => $changed,
                    'before' => array_intersect_key($before, array_flip($changed)),
                    'after' => array_intersect_key($this->auditableValues($locked), array_flip($changed)),
                ],
                null,
                $actor,
            );

            return $locked;
        });
    }

    public function delete(TimesheetSegment $segment, User $actor): void
    {
        $parent = $segment->timesheet()->first();

        if (! $parent instanceof Timesheet) {
            throw ValidationException::withMessages([
                'segment' => 'Ce segment ne peut pas être supprimé.',
            ]);
        }

        Gate::forUser($actor)->authorize('update', $parent);

        DB::transaction(function () use ($segment, $actor): void {
            // Ordre de verrouillage imposé : relevé parent, puis segment.
            $timesheet = $this->lockEditableTimesheetById((int) $segment->timesheet_id, $actor);

            $locked = TimesheetSegment::query()
                ->whereKey($segment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->timesheet_id !== (int) $timesheet->getKey()) {
                throw ValidationException::withMessages([
                    'segment' => 'Ce segment ne peut pas être supprimé.',
                ]);
            }

            $values = $this->auditableValues($locked);

            $locked->delete();

            $this->audit->record(
                AuditAction::TimesheetUpdated,
                $timesheet,
                [
                    'operation' => 'segment_deleted',
                    'segment_id' => (int) $values['segment_id'],
                    'category' => $values['category'],
                    'start_at' => $values['start_at'],
                    'end_at' => $values['end_at'],
                ],
                null,
                $actor,
            );
        });
    }

    private function lockEditableTimesheet(Timesheet $timesheet, User $actor): Timesheet
    {
        return $this->lockEditableTimesheetById((int) $timesheet->getKey(), $actor);
    }

    private function lockEditableTimesheetById(int $timesheetId, User $actor): Timesheet
    {
        $locked = Timesheet::query()
            ->whereKey($timesheetId)
            ->lockForUpdate()
            ->firstOrFail();

        Gate::forUser($actor)->authorize('update', $locked);

        if (! $locked->status->isEditable()) {
            throw ValidationException::withMessages([
                'timesheet' => 'Un relevé transmis ou validé ne peut plus être modifié.',
            ]);
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{category: TimeCategory, start_at: CarbonImmutable, end_at: CarbonImmutable}
     */
    private function normalize(array $data): array
    {
        $category = $data['category'] ?? null;

        if (! $category instanceof TimeCategory) {
            $category = is_string($category) ? TimeCategory::tryFrom($category) : null;
        }

        if (! $category instanceof TimeCategory) {
            throw ValidationException::withMessages([
                'category' => 'La catégorie du segment est invalide.',
            ]);
        }

        $start = $this->instant($data['start_at'] ?? null, 'start_at');
        $end = $this->instant($data['end_at'] ?? null, 'end_at');

        TimesheetMinutePrecision::assert($start, $end);

        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages([
                'end_at' => 'La fin d’un segment doit être postérieure à son début.',
            ]);
        }

        return [
            'category' => $category,
            'start_at' => $start,
            'end_at' => $end,
        ];
    }

    private function instant(mixed $value, string $field): CarbonImmutable
    {
        if (! $value instanceof CarbonInterface) {
            throw ValidationException::withMessages([
                $field => 'Un instant complet est requis pour un segment.',
            ]);
        }

        return CarbonImmutable::instance($value)->utc();
    }

    /**
     * @return array{segment_id: int, category: string, start_at: string, end_at: string}
     */
    private function auditableValues(TimesheetSegment $segment): array
    {
        return [
            'segment_id' => (int) $segment->getKey(),
            'category' => $segment->category->value,
            'start_at' => $segment->start_at?->toIso8601String() ?? '',
            'end_at' => $segment->end_at?->toIso8601String() ?? '',
        ];
    }
}
