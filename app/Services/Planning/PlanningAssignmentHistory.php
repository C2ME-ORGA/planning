<?php

namespace App\Services\Planning;

use App\Enums\AuditAction;
use App\Enums\PlanningHalfDay;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\PlanningAssignment;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Historique lisible d'une affectation de planning.
 *
 * Aucune table complémentaire n'est nécessaire : les événements
 * `planning.assignment_created` et `planning.assignment_updated` du journal
 * d'audit existant portent déjà l'auteur, l'horodatage, les valeurs avant/après
 * et les avertissements. Ce service en produit une projection strictement
 * limitée au planning :
 *
 * - seules les clés métier connues sont exposées ;
 * - le contexte technique de l'audit (adresse IP, agent utilisateur, source)
 *   n'est jamais renvoyé ;
 * - les noms de chantier, d'équipe et de salarié sont résolus à la lecture,
 *   jamais stockés dans le journal.
 *
 * Le journal complet reste réservé à `audit.view` ; cette projection suit les
 * droits de consultation du planning (`planning.manage` ou `planning.propose`).
 */
class PlanningAssignmentHistory
{
    /**
     * @param  Collection<int, PlanningAssignment>  $assignments
     * @return array<int, array<int, array{
     *     action: AuditAction,
     *     label: string,
     *     occurred_at: Carbon,
     *     actor_label: string,
     *     actor_role: string|null,
     *     details: array<int, string>,
     *     overlaps: array<int, array{type: string, label: string, message: string}>
     * }>>
     */
    public function timelinesFor(Collection $assignments, User $actor): array
    {
        if ($assignments->isEmpty()) {
            return [];
        }

        foreach ($assignments as $assignment) {
            Gate::forUser($actor)->authorize('viewHistory', $assignment);
        }

        /** @var array<int, string> $subjectIds */
        $subjectIds = $assignments
            ->map(static fn (PlanningAssignment $assignment): string => (string) $assignment->getKey())
            ->values()
            ->all();

        $events = AuditEvent::query()
            ->where('subject_type', PlanningAssignment::class)
            ->whereIn('subject_id', $subjectIds)
            ->whereIn('action', [
                AuditAction::PlanningAssignmentCreated->value,
                AuditAction::PlanningAssignmentUpdated->value,
            ])
            ->with('actor')
            ->orderByDesc('sequence')
            ->limit(200)
            ->get();

        $names = $this->resolveNames($events);

        $grouped = $events->groupBy(static fn (AuditEvent $event): string => (string) $event->subject_id);

        $timelines = [];

        foreach ($assignments as $assignment) {
            $timelines[(int) $assignment->getKey()] = $grouped
                ->get((string) $assignment->getKey(), collect())
                ->map(fn (AuditEvent $event): array => $this->entry($event, $names))
                ->values()
                ->all();
        }

        return $timelines;
    }

    /**
     * @return array<int, array{
     *     action: AuditAction,
     *     label: string,
     *     occurred_at: Carbon,
     *     actor_label: string,
     *     actor_role: string|null,
     *     details: array<int, string>,
     *     overlaps: array<int, array{type: string, label: string, message: string}>
     * }>
     */
    public function timeline(PlanningAssignment $assignment, User $actor): array
    {
        return $this->timelinesFor(collect([$assignment]), $actor)[(int) $assignment->getKey()] ?? [];
    }

    /**
     * @param  array{site: array<int, string>, team: array<int, string>, employee: array<int, string>}  $names
     * @return array{
     *     action: AuditAction,
     *     label: string,
     *     occurred_at: Carbon,
     *     actor_label: string,
     *     actor_role: string|null,
     *     details: array<int, string>,
     *     overlaps: array<int, array{type: string, label: string, message: string}>
     * }
     */
    private function entry(AuditEvent $event, array $names): array
    {
        $changes = is_array($event->changes) ? $event->changes : [];
        $before = is_array($changes['before'] ?? null) ? $changes['before'] : [];
        $after = is_array($changes['after'] ?? null) ? $changes['after'] : [];

        if ($before === [] && $after === []) {
            // Compatibilité avec les événements créés avant l'ajout des
            // instantanés avant/après : seules les clés connues sont lues.
            $after = $changes;
        }

        $added = $this->intList($changes['exclusions_added'] ?? []);
        $removed = $this->intList($changes['exclusions_removed'] ?? []);

        $details = $event->action === AuditAction::PlanningAssignmentCreated
            ? $this->describeValues($after, $names)
            : $this->describeDiff($before, $after, $names);

        if ($added !== []) {
            $details[] = 'Exclusions ajoutées : '.$this->employeeNames($added, $names);
        }

        if ($removed !== []) {
            $details[] = 'Exclusions retirées : '.$this->employeeNames($removed, $names);
        }

        $actor = $event->getRelation('actor');

        return [
            'action' => $event->action,
            'label' => $event->action->label(),
            'occurred_at' => Carbon::parse($event->occurred_at),
            'actor_label' => $actor instanceof User ? $actor->email : 'Système',
            'actor_role' => $this->actorRoleLabel($event),
            'details' => $details,
            'overlaps' => $this->overlaps($event, $names),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array{site: array<int, string>, team: array<int, string>, employee: array<int, string>}  $names
     * @return array<int, string>
     */
    private function describeValues(array $values, array $names): array
    {
        $details = [];

        if (isset($values['site_id'])) {
            $details[] = 'Chantier : '.$this->siteName((int) $values['site_id'], $names);
        }

        if (isset($values['team_id'])) {
            $details[] = 'Équipe : '.$this->teamName((int) $values['team_id'], $names);
        }

        if (isset($values['work_date']) && is_string($values['work_date'])) {
            $details[] = 'Date : '.$this->formatDate($values['work_date']);
        }

        if (isset($values['half_day']) && is_string($values['half_day'])) {
            $details[] = 'Demi-journée : '.$this->halfDayLabel($values['half_day']);
        }

        $excluded = $this->intList($values['excluded_employee_ids'] ?? []);

        if ($excluded !== []) {
            $details[] = 'Exclusions : '.$this->employeeNames($excluded, $names);
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array{site: array<int, string>, team: array<int, string>, employee: array<int, string>}  $names
     * @return array<int, string>
     */
    private function describeDiff(array $before, array $after, array $names): array
    {
        $labels = [
            'site_id' => 'Chantier',
            'team_id' => 'Équipe',
            'work_date' => 'Date',
            'half_day' => 'Demi-journée',
        ];

        $details = [];

        foreach ($labels as $key => $label) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if ($old === $new) {
                continue;
            }

            $details[] = sprintf(
                '%s : %s → %s',
                $label,
                $this->formatValue($key, $old, $names),
                $this->formatValue($key, $new, $names),
            );
        }

        return $details;
    }

    /**
     * @param  array{site: array<int, string>, team: array<int, string>, employee: array<int, string>}  $names
     */
    private function formatValue(string $key, mixed $value, array $names): string
    {
        if ($value === null) {
            return '—';
        }

        return match ($key) {
            'site_id' => $this->siteName((int) $value, $names),
            'team_id' => $this->teamName((int) $value, $names),
            'work_date' => $this->formatDate((string) $value),
            'half_day' => $this->halfDayLabel((string) $value),
            default => (string) $value,
        };
    }

    /**
     * @param  array{site: array<int, string>, team: array<int, string>, employee: array<int, string>}  $names
     * @return array<int, array{type: string, label: string, message: string}>
     */
    private function overlaps(AuditEvent $event, array $names): array
    {
        $context = is_array($event->context) ? $event->context : [];
        $overlaps = is_array($context['overlaps'] ?? null) ? $context['overlaps'] : [];

        $result = [];

        foreach ($overlaps as $overlap) {
            if (! is_array($overlap) || ! isset($overlap['type'], $overlap['site_id'], $overlap['team_id'])) {
                continue;
            }

            $site = $this->siteName((int) $overlap['site_id'], $names);
            $team = $this->teamName((int) $overlap['team_id'], $names);

            if ($overlap['type'] === 'team') {
                $result[] = [
                    'type' => 'team',
                    'label' => 'Chevauchement d’équipe',
                    'message' => sprintf('L’équipe %s est aussi affectée au chantier %s.', $team, $site),
                ];

                continue;
            }

            if ($overlap['type'] === 'employee' && isset($overlap['employee_id'])) {
                $employee = $this->employeeNames([(int) $overlap['employee_id']], $names);

                $result[] = [
                    'type' => 'employee',
                    'label' => 'Chevauchement de salarié',
                    'message' => sprintf('%s est aussi planifié sur le chantier %s (équipe %s).', $employee, $site, $team),
                ];
            }
        }

        return $result;
    }

    private function actorRoleLabel(AuditEvent $event): ?string
    {
        if ($event->actor_role === null || $event->actor_role === '') {
            return null;
        }

        return Role::tryFrom($event->actor_role)?->label() ?? $event->actor_role;
    }

    /**
     * @param  Collection<int, AuditEvent>  $events
     * @return array{site: array<int, string>, team: array<int, string>, employee: array<int, string>}
     */
    private function resolveNames(Collection $events): array
    {
        $siteIds = [];
        $teamIds = [];
        $employeeIds = [];

        foreach ($events as $event) {
            $changes = is_array($event->changes) ? $event->changes : [];
            $values = [$changes];

            if (is_array($changes['before'] ?? null)) {
                $values[] = $changes['before'];
            }

            if (is_array($changes['after'] ?? null)) {
                $values[] = $changes['after'];
            }

            foreach ($values as $value) {
                if (isset($value['site_id'])) {
                    $siteIds[] = (int) $value['site_id'];
                }

                if (isset($value['team_id'])) {
                    $teamIds[] = (int) $value['team_id'];
                }

                foreach (['excluded_employee_ids', 'exclusions_added', 'exclusions_removed'] as $key) {
                    foreach ($this->intList($value[$key] ?? []) as $employeeId) {
                        $employeeIds[] = $employeeId;
                    }
                }
            }

            $context = is_array($event->context) ? $event->context : [];
            $overlaps = is_array($context['overlaps'] ?? null) ? $context['overlaps'] : [];

            foreach ($overlaps as $overlap) {
                if (! is_array($overlap)) {
                    continue;
                }

                if (isset($overlap['site_id'])) {
                    $siteIds[] = (int) $overlap['site_id'];
                }

                if (isset($overlap['team_id'])) {
                    $teamIds[] = (int) $overlap['team_id'];
                }

                if (isset($overlap['employee_id'])) {
                    $employeeIds[] = (int) $overlap['employee_id'];
                }
            }
        }

        $siteNames = Site::query()
            ->whereIn('id', array_values(array_unique($siteIds)))
            ->pluck('name', 'id')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();

        $teamNames = Team::query()
            ->whereIn('id', array_values(array_unique($teamIds)))
            ->pluck('name', 'id')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();

        $employeeNames = Employee::query()
            ->whereIn('id', array_values(array_unique($employeeIds)))
            ->get()
            ->mapWithKeys(static fn (Employee $employee): array => [
                (int) $employee->getKey() => $employee->fullName(),
            ])
            ->all();

        /** @var array<int, string> $siteNames */
        /** @var array<int, string> $teamNames */
        /** @var array<int, string> $employeeNames */
        return [
            'site' => $siteNames,
            'team' => $teamNames,
            'employee' => $employeeNames,
        ];
    }

    /**
     * @param  array{site: array<int, string>, team: array<int, string>, employee: array<int, string>}  $names
     */
    private function siteName(int $id, array $names): string
    {
        return $names['site'][$id] ?? 'Chantier #'.$id;
    }

    /**
     * @param  array{site: array<int, string>, team: array<int, string>, employee: array<int, string>}  $names
     */
    private function teamName(int $id, array $names): string
    {
        return $names['team'][$id] ?? 'Équipe #'.$id;
    }

    /**
     * @param  array<int, int>  $ids
     * @param  array{site: array<int, string>, team: array<int, string>, employee: array<int, string>}  $names
     */
    private function employeeNames(array $ids, array $names): string
    {
        return collect($ids)
            ->map(static fn (int $id): string => $names['employee'][$id] ?? 'Salarié #'.$id)
            ->implode(', ');
    }

    /**
     * @return array<int, int>
     */
    private function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $item) {
            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                $ids[] = (int) $item;
            }
        }

        return array_values(array_unique($ids));
    }

    private function formatDate(string $value): string
    {
        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function halfDayLabel(string $value): string
    {
        return PlanningHalfDay::tryFrom($value)?->label() ?? $value;
    }
}
