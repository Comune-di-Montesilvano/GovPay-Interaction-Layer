<?php
declare(strict_types=1);

namespace App\Database;

/** Migrazione (o backup che la precede) fallita: il backoffice non deve avviarsi. */
final class MigrationFailedException extends \RuntimeException
{
}
