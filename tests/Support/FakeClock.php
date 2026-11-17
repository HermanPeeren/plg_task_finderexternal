<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Support;

use Yepr\Plugin\Task\FinderExternal\Run\Clock;

/**
 * A clock that only moves when told to: by sleep(), and by a fixed cost per request
 * that FakeHttpClient charges.
 */
final class FakeClock implements Clock
{
    private float $seconds = 1000.0;

    private \DateTimeImmutable $date;

    public function __construct(string $date = '2026-11-17 12:00:00')
    {
        $this->date = new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    public function seconds(): float
    {
        return $this->seconds;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function sleep(int $milliseconds): void
    {
        $this->advance($milliseconds / 1000);
    }

    public function advance(float $seconds): void
    {
        $this->seconds += $seconds;
        $this->date = $this->date->modify(\sprintf('+%d seconds', (int) round($seconds)));
    }

    public function days(int $days): void
    {
        $this->date = $this->date->modify(\sprintf('+%d days', $days));
    }
}
