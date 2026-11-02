<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Services\Jobs;

/**
 * Lavoro con una coda misurabile: quanti elementi restano da elaborare (COUNT economico che
 * rispecchia la query di selezione del batch). JobRunner lo legge all'avvio (totale del giro)
 * e dopo ogni batch (restanti) per avanzamento in stdout, gil_job_runs e pagina Cron.
 * null = non significativo (es. lavoro non configurato).
 */
interface CountsRemaining
{
    public function remaining(): ?int;
}
