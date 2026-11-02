<?php
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Database\JobStateRepository;

/**
 * Import una tantum dei file `daemon-<slug>.autostart` (demoni avviati all'avvio del container
 * nel vecchio sistema) nei flag gil_jobs.enabled.
 *
 * - Abilita i lavori noti con file presente (+ i lavori associati, JobCatalog::COMPANIONS);
 *   slug sconosciuti ignorati.
 * - Scrive il marker `__import__`: le chiamate successive non fanno nulla, quindi un lavoro
 *   disabilitato dopo l'import non viene riabilitato da un riavvio.
 * - Directory assente (volume cache non montato): nessun import e nessun marker, si riprova al
 *   prossimo avvio (marcare l'import qui perderebbe per sempre gli autostart esistenti).
 */
final class AutostartImporter
{
    /**
     * @param list<string> $jobNames lavori registrati
     * @param array<string, list<string>> $companions slug => lavori da abilitare insieme
     */
    public function __construct(
        private readonly JobStateRepository $state,
        private readonly array $jobNames,
        private readonly array $companions = JobCatalog::COMPANIONS
    ) {}

    /** Ritorna il numero di lavori abilitati (0 se gia' importato o directory assente). */
    public function importOnce(string $cacheDir): int
    {
        if ($this->state->autostartImported() || !is_dir($cacheDir)) {
            return 0;
        }

        $enable = [];
        foreach ($this->jobNames as $name) {
            if (is_file(rtrim($cacheDir, '/\\') . '/daemon-' . $name . '.autostart')) {
                $enable[$name] = true;
                foreach ($this->companions[$name] ?? [] as $companion) {
                    if (in_array($companion, $this->jobNames, true)) {
                        $enable[$companion] = true;
                    }
                }
            }
        }

        foreach (array_keys($enable) as $name) {
            $this->state->setEnabled($name, true);
        }
        $this->state->markAutostartImported();

        return count($enable);
    }
}
