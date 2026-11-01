<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Config\SettingsRepository;
use App\Database\JobStateRepository;
use App\Database\MassivePendenzeRepository;
use App\Services\Jobs\JobControl;
use App\Services\Jobs\RagioneriaSyncJob;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;

#[Group('db')]
final class JobControlTest extends DbTestCase
{
    protected static array $tables = ['gil_jobs', 'gil_job_runs', 'gil_job_run_errors', 'lock_keys', 'pendenze_massive'];

    private JobStateRepository $state;
    /** @var list<string> */
    private array $enqueued = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->state = new JobStateRepository(static fn(): \PDO => self::pdo());
        $this->enqueued = [];
        self::pdo()->exec("DELETE FROM settings WHERE section = 'backoffice' AND key_name = '" . RagioneriaSyncJob::RESCAN_SETTING . "'");
        SettingsRepository::flush();
    }

    private function control(): JobControl
    {
        return new JobControl(
            $this->state,
            function (string $job): void {
                $this->enqueued[] = $job;
            },
            static fn(): MassivePendenzeRepository => new MassivePendenzeRepository(self::pdo())
        );
    }

    public function testEnableAlsoEnablesCompanionAndSchedulesNextTick(): void
    {
        self::pdo()->exec("INSERT INTO gil_jobs (name, enabled, next_run_at) VALUES ('mapping', 0, '2099-01-01 00:00:00'), ('mapping-discovery', 0, '2099-01-01 00:00:00')");

        $this->control()->setEnabled('mapping', true);

        self::assertTrue($this->state->isEnabled('mapping'));
        self::assertTrue($this->state->isEnabled('mapping-discovery'));
        $due = $this->state->dueJobs(new \DateTimeImmutable());
        self::assertSame(['mapping', 'mapping-discovery'], $due);

        $this->control()->setEnabled('mapping', false);
        self::assertFalse($this->state->isEnabled('mapping'));
        self::assertFalse($this->state->isEnabled('mapping-discovery'));
        self::assertSame([], $this->state->dueJobs(new \DateTimeImmutable()));
    }

    public function testUnknownJobIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->control()->setEnabled('mapping-discovery', true);
    }

    public function testStatusesReportEnabledRunningAndLastRun(): void
    {
        self::pdo()->exec("INSERT INTO gil_jobs (name, enabled, last_started_at, last_finished_at, last_status, next_run_at)
            VALUES ('biz', 1, '2026-11-01 10:00:00', '2026-11-01 10:00:05', 'ok', '2026-11-01 10:15:05')");
        // lock tenuto (non scaduto) per mapping-discovery: conta come mapping in esecuzione;
        // lock scaduto per vocab: non in esecuzione.
        self::pdo()->exec("INSERT INTO lock_keys (key_id, key_token, key_expiration) VALUES
            ('job:mapping-discovery', 't1', UNIX_TIMESTAMP() + 600),
            ('job:vocab', 't2', UNIX_TIMESTAMP() - 1)");

        $s = $this->control()->statuses();

        self::assertSame(JobControl::VISIBLE, array_keys($s));
        self::assertTrue($s['biz']['enabled']);
        self::assertFalse($s['biz']['running']);
        self::assertSame('ok', $s['biz']['last_status']);
        self::assertSame('2026-11-01 10:15:05', $s['biz']['next_run_at']);
        self::assertTrue($s['mapping']['running']);
        self::assertFalse($s['vocab']['running']);
        self::assertFalse($s['tefa']['enabled']);
        self::assertNull($s['tefa']['last_status']);
    }

    public function testRunNowEnqueuesEvenWhenPausedExceptMassive(): void
    {
        self::assertNull($this->control()->runNow('biz'));
        self::assertSame(['biz'], $this->enqueued);

        $reason = $this->control()->runNow('pendenze-massive');
        self::assertNotNull($reason);
        self::assertStringContainsString('pausa', $reason);
        self::assertSame(['biz'], $this->enqueued);

        $this->state->setEnabled('pendenze-massive', true);
        self::assertNull($this->control()->runNow('pendenze-massive'));
        self::assertSame(['biz', 'pendenze-massive'], $this->enqueued);
    }

    public function testRunNowDefaultEnqueuesManualMessageOnJobsQueue(): void
    {
        self::pdo()->exec("DELETE FROM messenger_messages WHERE queue_name = 'jobs'");
        (new JobControl($this->state))->runNow('vocab');
        $n = (int)self::pdo()->query("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'jobs'")->fetchColumn();
        self::pdo()->exec("DELETE FROM messenger_messages WHERE queue_name = 'jobs'");
        self::assertSame(1, $n);
    }

    public function testLogShowsSummaryAndTopErrors(): void
    {
        $t0 = new \DateTimeImmutable('2026-11-01 09:00:00');
        $this->state->recordRun('biz', $t0, $t0->modify('+4 seconds'), 2, new \App\Services\Jobs\JobResult(10, 8, 0, 2, false), 'ok', null, [
            ['ref' => 'IUR-0001', 'reason' => 'HTTP 404'],
            ['ref' => 'IUR-0002', 'reason' => 'HTTP 500'],
        ], $t0->modify('+15 minutes'));
        $t1 = new \DateTimeImmutable('2026-11-01 09:30:00');
        $this->state->recordRun('biz', $t1, $t1->modify('+1 second'), 1, new \App\Services\Jobs\JobResult(0, 0, 0, 0, false), 'error', 'RuntimeException: id_dominio non configurato.', [], $t1->modify('+15 minutes'), true);

        $text = $this->control()->logText('biz');

        $lines = explode("\n", $text);
        self::assertStringStartsWith('Stato: in pausa', $lines[0]);
        // piu' recente prima
        self::assertStringContainsString('[2026-11-01 09:30:00 -> 09:30:01] ERROR (manuale)', $text);
        self::assertStringContainsString('errore: RuntimeException: id_dominio non configurato.', $text);
        self::assertStringContainsString('[2026-11-01 09:00:00 -> 09:00:04] OK: 2 batch, elaborati 10, ok 8, saltati 0, errori 2', $text);
        self::assertStringContainsString('- IUR-0001: HTTP 404', $text);
        self::assertStringContainsString('- IUR-0002: HTTP 500', $text);
        self::assertLessThan(strpos($text, '09:00:00'), strpos($text, '09:30:00'));
    }

    public function testLogWithoutRuns(): void
    {
        self::assertStringContainsString('(nessuna esecuzione registrata)', $this->control()->logText('tefa'));
    }

    public function testRagioneriaRescanRequiresEnabledAndIsConsumedOnce(): void
    {
        self::assertNotNull($this->control()->requestRagioneriaRescan());
        SettingsRepository::flush();
        self::assertFalse(RagioneriaSyncJob::takeRescanRequest());

        self::pdo()->exec("INSERT INTO gil_jobs (name, enabled, next_run_at) VALUES ('ragioneria', 1, '2099-01-01 00:00:00')");
        self::assertNull($this->control()->requestRagioneriaRescan());
        self::assertSame(['ragioneria'], $this->state->dueJobs(new \DateTimeImmutable()));

        SettingsRepository::flush();
        self::assertTrue(RagioneriaSyncJob::takeRescanRequest());
        SettingsRepository::flush();
        self::assertFalse(RagioneriaSyncJob::takeRescanRequest());
    }

    public function testRetryMassiveRowOnlyForWorkerErrors(): void
    {
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO pendenze_massive (id, file_batch_id, riga, stato, errore, payload_json, response_json, claimed_at) VALUES
            (1, 'b', 1, 'ERROR', 'HTTP 503', '{}', '{\"idPendenza\":\"GIL-r\"}', NOW()),
            (2, 'b', 2, 'ERROR', 'CF non valido', '{}', NULL, NULL),
            (3, 'b', 3, 'SUCCESS', NULL, '{}', '{\"idPendenza\":\"GIL-s\"}', NOW())");

        $r = $this->control()->retryMassiveRow(1);
        self::assertTrue($r['ok']);
        self::assertStringContainsString('riattivato', $r['message']); // servizio in pausa
        $row = $pdo->query('SELECT stato, response_json, claimed_at FROM pendenze_massive WHERE id = 1')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('PENDING', $row['stato']);
        self::assertNull($row['claimed_at']);
        self::assertStringContainsString('GIL-r', (string)$row['response_json']);

        $r = $this->control()->retryMassiveRow(2);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('validazione', $r['message']);
        self::assertSame('ERROR', $pdo->query('SELECT stato FROM pendenze_massive WHERE id = 2')->fetchColumn());

        self::assertFalse($this->control()->retryMassiveRow(3)['ok']);
        self::assertFalse($this->control()->retryMassiveRow(99)['ok']);
        // seconda volta sulla riga gia' rimessa in coda: non piu' in errore
        self::assertFalse($this->control()->retryMassiveRow(1)['ok']);
    }
}
