<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Services\Jobs;

/**
 * Cosa esegue un processo WorkerKernel.
 *
 * - jobGroup(): un processo figlio per gruppo di lavori (WorkerSupervisor): scheduler e coda
 *   "esegui ora" (`jobs.<name>`) solo dei lavori del gruppo. Gruppi = JobCatalog::processGroups().
 * - massive(): il processo figlio che consuma la coda `massive` (righe pendenze massive).
 * - housekeeping(): solo attesa DB, import .autostart, pulizia storico (il supervisore).
 * - all(): tutto in un processo, come il worker sequenziale precedente (test/diagnostica):
 *   tutti i lavori, coda `massive`, coda manuale legacy `jobs`, housekeeping.
 */
final class WorkerScope
{
    /**
     * @param list<string>|null $jobs lavori del processo (null = tutti quelli del registro)
     */
    private function __construct(
        public readonly ?array $jobs,
        public readonly bool $massive,
        public readonly bool $housekeeping,
        public readonly bool $legacyQueue,
        public readonly string $label
    ) {}

    public static function all(): self
    {
        return new self(null, true, true, true, 'worker');
    }

    /** @param list<string> $jobs */
    public static function jobGroup(string $group, array $jobs): self
    {
        return new self(array_values($jobs), false, false, false, 'worker:' . $group);
    }

    public static function massive(): self
    {
        return new self([], true, false, false, 'worker:massive');
    }

    public static function housekeeping(): self
    {
        return new self([], false, true, false, 'worker');
    }
}
