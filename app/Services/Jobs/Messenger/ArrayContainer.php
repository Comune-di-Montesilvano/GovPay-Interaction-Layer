<?php
declare(strict_types=1);

namespace App\Services\Jobs\Messenger;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/** Locator minimo (id -> oggetto) per i listener Messenger che chiedono un ContainerInterface. */
final class ArrayContainer implements ContainerInterface
{
    /** @param array<string, object> $entries */
    public function __construct(private readonly array $entries) {}

    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->entries)) {
            throw new class('Servizio non registrato: ' . $id) extends \RuntimeException implements NotFoundExceptionInterface {};
        }
        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}
