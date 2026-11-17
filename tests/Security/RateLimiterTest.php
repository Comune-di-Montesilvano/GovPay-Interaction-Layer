<?php
declare(strict_types=1);

namespace Tests\Security;

use App\Security\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;

#[Group('db')]
final class RateLimiterTest extends DbTestCase
{
    protected static array $tables = ['rate_limit_buckets'];

    public function testBlocksAfterLimit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue(RateLimiter::check('t:block', 3, 900));
        }
        $this->assertFalse(RateLimiter::check('t:block', 3, 900));
    }

    public function testResetClearsCounter(): void
    {
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::check('t:reset', 3, 900);
        }
        RateLimiter::reset('t:reset');
        $this->assertTrue(RateLimiter::check('t:reset', 3, 900));
        $this->assertTrue(RateLimiter::check('t:other', 3, 900));
    }
}
