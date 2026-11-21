<?php
declare(strict_types=1);

/**
 * Stato del GovPay/pagoPA finto: un file JSON condiviso dalle richieste del server `php -S`
 * (ogni richiesta e' un processo/thread a se'). Lettura-modifica-scrittura sotto flock su un
 * file .lock, scrittura via rename atomico. Solo dati sintetici.
 */
final class FakeState
{
    private const EMPTY = [
        'seq' => 0,
        'pendenze' => [],
        'paid' => [],
        'carts' => [],
        'requests' => [],
        'unmatched' => [],
        'failNext' => [],
    ];

    public function __construct(private readonly string $file)
    {
    }

    public static function fromEnv(): self
    {
        $file = getenv('FAKE_GOVPAY_STATE') ?: (sys_get_temp_dir() . '/gil-fake-govpay.json');
        return new self($file);
    }

    /**
     * Applica $fn allo stato sotto lock e salva il risultato.
     *
     * @template T
     * @param callable(array<string,mixed>):array{0:array<string,mixed>,1:T} $fn riceve lo stato, ritorna [nuovo stato, valore]
     * @return T
     */
    public function update(callable $fn): mixed
    {
        $lock = fopen($this->file . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('lock stato finto non apribile');
        }
        try {
            flock($lock, LOCK_EX);
            [$state, $value] = $fn($this->read());
            $tmp = $this->file . '.' . getmypid() . '.tmp';
            file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            rename($tmp, $this->file);
            return $value;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string,mixed> */
    public function read(): array
    {
        if (!is_file($this->file)) {
            return self::EMPTY;
        }
        $data = json_decode((string)file_get_contents($this->file), true);
        return is_array($data) ? $data + self::EMPTY : self::EMPTY;
    }

    public function reset(): void
    {
        $this->update(static fn(array $_): array => [self::EMPTY, null]);
    }
}
