<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Support;

use Yepr\Plugin\Task\FinderExternal\Store\UrlRecord;
use Yepr\Plugin\Task\FinderExternal\Store\UrlStore;

/**
 * UrlStore in memory, with the same due rule as the database store.
 */
final class MemoryUrlStore implements UrlStore
{
    /**
     * @var array<int, array{task: int, url: string, depth: int, sitemap: bool, seen: int, checkedCycle: int, checked: ?string, record: UrlRecord}>
     */
    public array $rows = [];

    private int $nextId = 1;

    public function add(int $taskId, array $urls, int $depth, bool $inSitemap, int $cycle): int
    {
        $new = 0;

        foreach (array_unique($urls) as $url) {
            $id = $this->find($taskId, $url);

            if ($id !== null) {
                $row               = $this->rows[$id];
                $row['seen']       = $cycle;
                $row['sitemap']    = $row['sitemap'] || $inSitemap;
                $lower             = min($row['depth'], $depth);
                $row['depth']      = $lower;
                $record            = $row['record'];
                $row['record']     = new UrlRecord(
                    $id,
                    $url,
                    $lower,
                    $record->status,
                    $record->linkId,
                    $record->contentHash,
                    $record->etag,
                    $record->lastModified,
                    $record->message
                );
                $this->rows[$id]   = $row;

                continue;
            }

            $id              = $this->nextId++;
            $this->rows[$id] = [
                'task'         => $taskId,
                'url'          => $url,
                'depth'        => $depth,
                'sitemap'      => $inSitemap,
                'seen'         => $cycle,
                'checkedCycle' => 0,
                'checked'      => null,
                'record'       => new UrlRecord($id, $url, $depth),
            ];
            $new++;
        }

        return $new;
    }

    public function due(int $taskId, int $cycle, \DateTimeImmutable $checkedBefore, int $limit): array
    {
        $due = array_filter($this->rows, fn (array $row): bool => $this->isDue($row, $taskId, $cycle, $checkedBefore));

        uasort($due, static fn (array $a, array $b): int => [$a['depth'], $a['record']->id] <=> [$b['depth'], $b['record']->id]);

        return array_values(array_map(static fn (array $row): UrlRecord => clone $row['record'], \array_slice($due, 0, $limit)));
    }

    public function countDue(int $taskId, int $cycle, \DateTimeImmutable $checkedBefore): int
    {
        return \count(array_filter($this->rows, fn (array $row): bool => $this->isDue($row, $taskId, $cycle, $checkedBefore)));
    }

    public function checked(UrlRecord $record, int $cycle, \DateTimeImmutable $now): void
    {
        $row                     = $this->rows[$record->id];
        $row['record']           = clone $record;
        $row['checkedCycle']     = $cycle;
        $row['checked']          = $now->format('Y-m-d H:i:s');
        $this->rows[$record->id] = $row;
    }

    public function droppedFromSitemap(int $taskId, int $cycle, int $limit): array
    {
        $rows = array_filter($this->rows, static fn (array $row): bool => $row['task'] === $taskId && $row['sitemap'] && $row['seen'] < $cycle);

        return array_values(array_map(static fn (array $row): UrlRecord => clone $row['record'], \array_slice($rows, 0, $limit)));
    }

    public function delete(int $id): void
    {
        unset($this->rows[$id]);
    }

    public function linkIds(int $taskId): array
    {
        $ids = [];

        foreach ($this->rows as $row) {
            if ($row['task'] === $taskId && $row['record']->linkId > 0) {
                $ids[] = $row['record']->linkId;
            }
        }

        return $ids;
    }

    public function forgetLinks(int $taskId, array $linkIds): void
    {
        foreach ($this->rows as $id => $row) {
            if ($row['task'] === $taskId && \in_array($row['record']->linkId, $linkIds, true)) {
                $row['record']   = new UrlRecord($id, $row['url'], $row['depth']);
                $row['checked']  = null;
                $this->rows[$id] = $row;
            }
        }
    }

    public function record(string $url): ?UrlRecord
    {
        foreach ($this->rows as $row) {
            if ($row['url'] === $url) {
                return $row['record'];
            }
        }

        return null;
    }

    /**
     * @param   array{task: int, url: string, depth: int, sitemap: bool, seen: int, checkedCycle: int, checked: ?string, record: UrlRecord}  $row
     */
    private function isDue(array $row, int $taskId, int $cycle, \DateTimeImmutable $checkedBefore): bool
    {
        return $row['task'] === $taskId
            && $row['checkedCycle'] < $cycle
            && ($row['depth'] === 0 || $row['checked'] === null || $row['checked'] < $checkedBefore->format('Y-m-d H:i:s'));
    }

    private function find(int $taskId, string $url): ?int
    {
        foreach ($this->rows as $id => $row) {
            if ($row['task'] === $taskId && $row['url'] === $url) {
                return $id;
            }
        }

        return null;
    }
}
