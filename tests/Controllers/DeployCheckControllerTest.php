<?php
declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\DeployCheckController;
use App\Database\MigrationRunner;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Support\DbTestCase;

#[Group('db')]
final class DeployCheckControllerTest extends DbTestCase
{
    /** @return array<string, mixed> */
    private function call(): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/internal/deploy-check');
        $response = (new DeployCheckController())->status($request, new Response());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string)$response->getBody(), true);
        self::assertIsArray($data);
        return $data;
    }

    public function testSchemaAggiornatoNessunaPendente(): void
    {
        $data = $this->call();

        self::assertTrue($data['success']);
        self::assertTrue($data['db']);
        self::assertSame([], $data['migrations']['pending']);
        self::assertSame(count(glob(MigrationRunner::migrationsDir() . '/*.sql') ?: []), $data['migrations']['registered']);
        self::assertIsString($data['version']);
    }

    public function testFileNonRegistratoRisultaPendente(): void
    {
        $pdo = self::pdo();
        $row = $pdo->query("SELECT filename, checksum, applied_at FROM gil_schema_migrations ORDER BY filename DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $pdo->prepare('DELETE FROM gil_schema_migrations WHERE filename = ?')->execute([$row['filename']]);
        try {
            $data = $this->call();
            self::assertSame([$row['filename']], $data['migrations']['pending']);
        } finally {
            $pdo->prepare('INSERT INTO gil_schema_migrations (filename, checksum, applied_at) VALUES (?, ?, ?)')
                ->execute([$row['filename'], $row['checksum'], $row['applied_at']]);
        }
    }
}
