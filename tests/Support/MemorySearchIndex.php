<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Support;

use Yepr\Plugin\Task\FinderExternal\Index\IndexEntry;
use Yepr\Plugin\Task\FinderExternal\Index\SearchIndex;

/**
 * The Smart Search index as an array of entries by link id. Like the real indexer, one
 * URL keeps its link id when it is indexed again.
 */
final class MemorySearchIndex implements SearchIndex
{
    /**
     * @var array<int, IndexEntry>
     */
    public array $entries = [];

    public int $writes = 0;

    private int $nextId = 1;

    public function add(IndexEntry $entry): int
    {
        $this->writes++;

        foreach ($this->entries as $id => $existing) {
            if ($existing->url === $entry->url) {
                $this->entries[$id] = $entry;

                return $id;
            }
        }

        $id                 = $this->nextId++;
        $this->entries[$id] = $entry;

        return $id;
    }

    public function remove(int $linkId): void
    {
        unset($this->entries[$linkId]);
    }

    public function missing(array $linkIds): array
    {
        return array_values(array_filter($linkIds, fn (int $id): bool => !isset($this->entries[$id])));
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    /**
     * @return  list<string>
     */
    public function urls(): array
    {
        $urls = array_map(static fn (IndexEntry $entry): string => $entry->url, array_values($this->entries));
        sort($urls);

        return $urls;
    }

    public function entry(string $url): ?IndexEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->url === $url) {
                return $entry;
            }
        }

        return null;
    }
}
