<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Store;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * UrlStore on the table #__finderexternal_urls.
 *
 * A URL can be longer than an index allows, so rows are found by the SHA-1 of the URL.
 */
final class DatabaseUrlStore implements UrlStore
{
	private const TABLE = '#__finderexternal_urls';

	/**
	 * Rows per query when adding many URLs at once (a sitemap).
	 */
	private const CHUNK = 200;

	public function __construct(private readonly DatabaseInterface $db)
	{
	}

	public function add(int $taskId, array $urls, int $depth, bool $inSitemap, int $cycle): int
	{
		$new = 0;

		foreach (array_chunk(array_values(array_unique($urls)), self::CHUNK) as $chunk) {
			$byHash = [];

			foreach ($chunk as $url) {
				$byHash[sha1($url)] = $url;
			}

			$query = $this->db->createQuery()
				->select([$this->db->quoteName('id'), $this->db->quoteName('url_hash')])
				->from($this->db->quoteName(self::TABLE))
				->where($this->db->quoteName('task_id') . ' = :task')
				->whereIn($this->db->quoteName('url_hash'), array_keys($byHash), ParameterType::STRING)
				->bind(':task', $taskId, ParameterType::INTEGER);

			/** @var array<string, int|string> $existing */
			$existing = $this->db->setQuery($query)->loadAssocList('url_hash', 'id');

			if ($existing !== []) {
				$query = $this->db->createQuery()
					->update($this->db->quoteName(self::TABLE))
					->set($this->db->quoteName('seen_cycle') . ' = :cycle')
					->set($this->db->quoteName('depth') . ' = LEAST(' . $this->db->quoteName('depth') . ', :depth)')
					->whereIn($this->db->quoteName('id'), array_map('intval', array_values($existing)), ParameterType::INTEGER)
					->bind(':cycle', $cycle, ParameterType::INTEGER)
					->bind(':depth', $depth, ParameterType::INTEGER);

				if ($inSitemap) {
					$query->set($this->db->quoteName('in_sitemap') . ' = 1');
				}

				$this->db->setQuery($query)->execute();
			}

			$fresh = array_diff_key($byHash, $existing);

			if ($fresh === []) {
				continue;
			}

			$sitemap = $inSitemap ? 1 : 0;
			$status  = UrlRecord::NEW;
			$query   = $this->db->createQuery()
				->insert($this->db->quoteName(self::TABLE))
				->columns($this->db->quoteName(['task_id', 'url', 'url_hash', 'depth', 'in_sitemap', 'seen_cycle', 'status']))
				->bind(':task', $taskId, ParameterType::INTEGER)
				->bind(':depth', $depth, ParameterType::INTEGER)
				->bind(':sitemap', $sitemap, ParameterType::INTEGER)
				->bind(':cycle', $cycle, ParameterType::INTEGER)
				->bind(':status', $status);

			$row = 0;

			foreach ($fresh as $hash => $url) {
				$query->values(\sprintf(':task, :url%1$d, :hash%1$d, :depth, :sitemap, :cycle, :status', $row));

				// Bound by reference: each row needs a variable of its own.
				$urlValue  = (string) $url;
				$hashValue = (string) $hash;
				$query->bind(':url' . $row, $urlValue);
				$query->bind(':hash' . $row, $hashValue);
				unset($urlValue, $hashValue);
				$row++;
			}

			$this->db->setQuery($query)->execute();
			$new += $row;
		}

		return $new;
	}

	public function due(int $taskId, int $cycle, \DateTimeImmutable $checkedBefore, int $limit): array
	{
		$query = $this->dueQuery($taskId, $cycle, $checkedBefore)
			->select('*')
			->order([$this->db->quoteName('depth'), $this->db->quoteName('id')]);

		return array_values(array_map($this->record(...), $this->db->setQuery($query, 0, $limit)->loadObjectList()));
	}

	public function countDue(int $taskId, int $cycle, \DateTimeImmutable $checkedBefore): int
	{
		$query = $this->dueQuery($taskId, $cycle, $checkedBefore)->select('COUNT(*)');

		return (int) $this->db->setQuery($query)->loadResult();
	}

	public function checked(UrlRecord $record, int $cycle, \DateTimeImmutable $now): void
	{
		$date    = $now->format('Y-m-d H:i:s');
		$message = mb_substr($record->message, 0, 255);
		$etag    = mb_substr($record->etag, 0, 255);
		$id      = $record->id;

		$query = $this->db->createQuery()
			->update($this->db->quoteName(self::TABLE))
			->set($this->db->quoteName('status') . ' = :status')
			->set($this->db->quoteName('link_id') . ' = :link')
			->set($this->db->quoteName('content_hash') . ' = :hash')
			->set($this->db->quoteName('etag') . ' = :etag')
			->set($this->db->quoteName('last_modified') . ' = :modified')
			->set($this->db->quoteName('message') . ' = :message')
			->set($this->db->quoteName('last_checked') . ' = :checked')
			->set($this->db->quoteName('checked_cycle') . ' = :cycle')
			->where($this->db->quoteName('id') . ' = :id')
			->bind(':status', $record->status)
			->bind(':link', $record->linkId, ParameterType::INTEGER)
			->bind(':hash', $record->contentHash)
			->bind(':etag', $etag)
			->bind(':modified', $record->lastModified)
			->bind(':message', $message)
			->bind(':checked', $date)
			->bind(':cycle', $cycle, ParameterType::INTEGER)
			->bind(':id', $id, ParameterType::INTEGER);

		$this->db->setQuery($query)->execute();
	}

	public function droppedFromSitemap(int $taskId, int $cycle, int $limit): array
	{
		$query = $this->db->createQuery()
			->select('*')
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('task_id') . ' = :task')
			->where($this->db->quoteName('in_sitemap') . ' = 1')
			->where($this->db->quoteName('seen_cycle') . ' < :cycle')
			->order($this->db->quoteName('id'))
			->bind(':task', $taskId, ParameterType::INTEGER)
			->bind(':cycle', $cycle, ParameterType::INTEGER);

		return array_values(array_map($this->record(...), $this->db->setQuery($query, 0, $limit)->loadObjectList()));
	}

	public function delete(int $id): void
	{
		$query = $this->db->createQuery()
			->delete($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('id') . ' = :id')
			->bind(':id', $id, ParameterType::INTEGER);

		$this->db->setQuery($query)->execute();
	}

	public function linkIds(int $taskId): array
	{
		$query = $this->db->createQuery()
			->select($this->db->quoteName('link_id'))
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('task_id') . ' = :task')
			->where($this->db->quoteName('link_id') . ' > 0')
			->bind(':task', $taskId, ParameterType::INTEGER);

		return array_values(array_map('intval', $this->db->setQuery($query)->loadColumn() ?: []));
	}

	public function forgetLinks(int $taskId, array $linkIds): void
	{
		foreach (array_chunk($linkIds, 500) as $chunk) {
			$status = UrlRecord::NEW;
			$query  = $this->db->createQuery()
				->update($this->db->quoteName(self::TABLE))
				->set($this->db->quoteName('status') . ' = :status')
				->set($this->db->quoteName('link_id') . ' = 0')
				->set($this->db->quoteName('content_hash') . ' = ' . $this->db->quote(''))
				->set($this->db->quoteName('etag') . ' = ' . $this->db->quote(''))
				->set($this->db->quoteName('last_modified') . ' = ' . $this->db->quote(''))
				->set($this->db->quoteName('last_checked') . ' = NULL')
				->where($this->db->quoteName('task_id') . ' = :task')
				->whereIn($this->db->quoteName('link_id'), $chunk, ParameterType::INTEGER)
				->bind(':status', $status)
				->bind(':task', $taskId, ParameterType::INTEGER);

			$this->db->setQuery($query)->execute();
		}
	}

	private function dueQuery(int $taskId, int $cycle, \DateTimeImmutable $checkedBefore): \Joomla\Database\QueryInterface
	{
		$before = $checkedBefore->format('Y-m-d H:i:s');

		return $this->db->createQuery()
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('task_id') . ' = :task')
			->where($this->db->quoteName('checked_cycle') . ' < :cycle')
			->where(
				'(' . $this->db->quoteName('depth') . ' = 0'
				. ' OR ' . $this->db->quoteName('last_checked') . ' IS NULL'
				. ' OR ' . $this->db->quoteName('last_checked') . ' < :before)'
			)
			->bind(':task', $taskId, ParameterType::INTEGER)
			->bind(':cycle', $cycle, ParameterType::INTEGER)
			->bind(':before', $before);
	}

	private function record(object $row): UrlRecord
	{
		$value = static fn (string $name): string => isset($row->{$name}) && \is_scalar($row->{$name}) ? (string) $row->{$name} : '';

		return new UrlRecord(
			(int) $value('id'),
			$value('url'),
			(int) $value('depth'),
			$value('status'),
			(int) $value('link_id'),
			$value('content_hash'),
			$value('etag'),
			$value('last_modified'),
			$value('message')
		);
	}
}
