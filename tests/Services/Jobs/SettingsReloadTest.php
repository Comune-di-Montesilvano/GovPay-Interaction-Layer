<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Auth\UserRepository;
use App\Config\SettingsRepository;
use App\Database\BizRepository;
use App\Database\FlussiRendicontazioniRepository;
use App\Database\MappingPendenzeRepository;
use App\Database\MassivePendenzeRepository;
use App\Database\RendicontazioneRepository;
use App\Database\TefaRepository;
use App\Database\UserGroupRepository;
use App\Services\BizScannerService;
use App\Services\GovPayDebitoreService;
use App\Services\Jobs\BizScanJob;
use App\Services\Jobs\GovPayDebitoreJob;
use App\Services\Jobs\MappingL1Job;
use App\Services\Jobs\MappingL2Job;
use App\Services\Jobs\MassivePendenzeProcessor;
use App\Services\Jobs\RagioneriaSyncJob;
use App\Services\Jobs\RendicontazioneJob;
use App\Services\Jobs\TefaScanJob;
use App\Services\MailerService;
use App\Services\RendicontazioneEngineService;
use App\Services\TefaScannerService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;
use Tests\Support\FakeGovPayPendenzeController;
use Tests\Support\RecordingJobContext;

/**
 * N5 (fix 8b): ogni runOnce azzera la cache statica di SettingsRepository, cosi' un demone
 * longevo vede le impostazioni cambiate da UI senza riavvio. Il valore viene cambiato
 * direttamente su DB (senza SettingsRepository::set, che invaliderebbe la cache da solo).
 * Nessuna chiamata esterna: dipendenze stub, GovPay su MockHandler vuoto, stop richiesto.
 */
#[Group('db')]
final class SettingsReloadTest extends DbTestCase
{
    protected static array $tables = ['settings'];

    private const DOMINIO = '80000000001';

    protected function setUp(): void
    {
        parent::setUp();
        SettingsRepository::flush();
    }

    protected function tearDown(): void
    {
        SettingsRepository::flush();
        parent::tearDown();
    }

    /** @return array<string,array{string}> */
    public static function jobs(): array
    {
        return [
            'biz' => ['biz'], 'tefa' => ['tefa'], 'debitore' => ['debitore'],
            'mappingL1' => ['mappingL1'], 'mappingL2' => ['mappingL2'], 'ragioneria' => ['ragioneria'],
            'massive' => ['massive'], 'rendicontazione' => ['rendicontazione'],
        ];
    }

    private function makeJob(string $name): object
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([]))]);
        return match ($name) {
            'biz' => new BizScanJob($this->createStub(BizRepository::class), $this->createStub(FlussiRendicontazioniRepository::class), $this->createStub(BizScannerService::class), self::DOMINIO, static fn() => null),
            'tefa' => new TefaScanJob($this->createStub(TefaRepository::class), $this->createStub(BizRepository::class), $this->createStub(TefaScannerService::class), self::DOMINIO),
            'debitore' => new GovPayDebitoreJob($this->createStub(GovPayDebitoreService::class), self::DOMINIO, static fn() => null),
            'mappingL1' => new MappingL1Job($this->createStub(MappingPendenzeRepository::class), self::DOMINIO),
            'mappingL2' => new MappingL2Job($this->createStub(MappingPendenzeRepository::class), self::DOMINIO),
            'ragioneria' => new RagioneriaSyncJob(self::DOMINIO, 'GIL-TEST', 'https://govpay.example.test/backoffice/v1', $client, $this->createStub(FlussiRendicontazioniRepository::class)),
            'massive' => new MassivePendenzeProcessor(new FakeGovPayPendenzeController(), $this->createStub(MassivePendenzeRepository::class)),
            'rendicontazione' => new RendicontazioneJob(
                $this->createStub(RendicontazioneRepository::class),
                fn() => $this->createStub(RendicontazioneEngineService::class),
                $this->createStub(UserGroupRepository::class),
                $this->createStub(UserRepository::class),
                $this->createStub(MailerService::class),
                self::DOMINIO,
                'GIL-TEST',
                self::pdo()
            ),
        };
    }

    #[DataProvider('jobs')]
    public function testRunOnceRilegeLeImpostazioniCambiateSuDb(string $name): void
    {
        SettingsRepository::set('test', 'marker', 'prima');
        self::assertSame('prima', SettingsRepository::get('test', 'marker')); // cache caricata
        self::pdo()->exec("UPDATE settings SET value = 'dopo' WHERE section = 'test' AND key_name = 'marker'");
        self::assertSame('prima', SettingsRepository::get('test', 'marker'), 'premessa: cache stale');

        $job = $this->makeJob($name);
        $ctx = new RecordingJobContext();
        $ctx->stop = true;
        // dipendenze stub (array vuoti): notice/eccezioni del resto dell'iterazione sono irrilevanti,
        // conta solo che runOnce abbia azzerato la cache all'inizio
        set_error_handler(static fn(): bool => true);
        try {
            $job->runOnce($ctx);
        } catch (\Throwable) {
        } finally {
            restore_error_handler();
        }

        self::assertSame('dopo', SettingsRepository::get('test', 'marker'));
    }
}
