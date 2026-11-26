<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\TipologiaLabel;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;

/** Etichetta della tipologia nelle notifiche al cittadino: descrizione, mai il codice tassonomia. */
#[Group('db')]
final class TipologiaLabelTest extends DbTestCase
{
    protected static array $tables = ['entrate_tipologie'];

    private const DOMINIO = '01234567890';

    private function insert(string $idEntrata, string $descrizione, ?string $locale = null): void
    {
        $now = date('Y-m-d H:i:s');
        self::pdo()->prepare(
            'INSERT INTO entrate_tipologie (id_dominio, id_entrata, descrizione, descrizione_locale, abilitato_backoffice, effective_enabled, updated_at, created_at)
             VALUES (?, ?, ?, ?, 1, 1, ?, ?)'
        )->execute([self::DOMINIO, $idEntrata, $descrizione, $locale, $now, $now]);
    }

    public function testDescrizioneGovPayAlPostoDelCodice(): void
    {
        $this->insert('AFFITTO_SALE', 'Affitto sale comunali');
        self::assertSame('Affitto sale comunali', TipologiaLabel::of('AFFITTO_SALE', self::DOMINIO));
    }

    public function testDescrizioneLocaleHaPrecedenza(): void
    {
        $this->insert('AFFITTO_SALE', 'Affitto sale comunali', 'Affitto sale e locali');
        self::assertSame('Affitto sale e locali', TipologiaLabel::of('AFFITTO_SALE', self::DOMINIO));
    }

    /** Email di creazione pendenza: descrizione della tipologia, non il codice tassonomia. */
    public function testEmailCreazionePendenzaMostraLaDescrizione(): void
    {
        $this->insert('AFFITTO_SALE', 'Affitto sale comunali');
        $m = new \ReflectionMethod(\App\Services\MailerService::class, 'renderPendenzaCreatedTemplate');
        $html = (string)$m->invokeArgs(\App\Services\MailerService::forSuite('backoffice'), [
            'Mario Rossi',
            ['causale' => 'Affitto sala', 'importo' => 10.0, 'idTipoPendenza' => 'AFFITTO_SALE', 'idDominio' => self::DOMINIO],
            'Ente', '', '', false, '',
        ]);
        self::assertStringContainsString('<strong>Tipologia:</strong> Affitto sale comunali', $html);
        self::assertStringNotContainsString('AFFITTO_SALE', $html);
    }

    public function testTipologiaSconosciutaOVuotaRestaIlCodice(): void
    {
        $this->insert('VUOTA', '   ');
        self::assertSame('NON_CENSITA', TipologiaLabel::of('NON_CENSITA', self::DOMINIO));
        self::assertSame('VUOTA', TipologiaLabel::of('VUOTA', self::DOMINIO));
        self::assertSame('', TipologiaLabel::of('', self::DOMINIO));
    }
}
