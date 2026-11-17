<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Support;

use Yepr\Plugin\Task\FinderExternal\Store\Cycle;
use Yepr\Plugin\Task\FinderExternal\Store\CycleStore;

/**
 * Cycles in memory, cloned in and out as the database would.
 */
final class MemoryCycleStore implements CycleStore
{
    /**
     * @var array<int, Cycle>
     */
    private array $cycles = [];

    public function load(int $taskId): ?Cycle
    {
        return isset($this->cycles[$taskId]) ? clone $this->cycles[$taskId] : null;
    }

    public function save(Cycle $cycle): void
    {
        $this->cycles[$cycle->taskId] = clone $cycle;
    }
}
