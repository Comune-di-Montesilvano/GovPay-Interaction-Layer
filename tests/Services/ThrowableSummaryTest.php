<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\ThrowableSummary;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ThrowableSummaryTest extends TestCase
{
    public function testClasseBreveSenzaMessaggio(): void
    {
        self::assertSame('RuntimeException', ThrowableSummary::of(new \RuntimeException('CF RSSMRA80A01H501U')));
        self::assertSame('ConnectException', ThrowableSummary::of(new ConnectException('https://x.test/?q=segreto', new Request('GET', 'https://x.test'))));
    }

    public function testStatusHttpQuandoCeUnaRisposta(): void
    {
        $e = new ClientException('segreto', new Request('GET', 'https://x.test/?cf=RSSMRA'), new Response(404, [], 'segreto'));
        self::assertSame('ClientException (HTTP 404)', ThrowableSummary::of($e));
    }
}
