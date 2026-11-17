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
 * CycleStore on the table #__finderexternal_cycles: one row per task, the queue of
 * sources, robots.txt texts and counters as JSON.
 */
final class DatabaseCycleStore implements CycleStore
{
	private const TABLE = '#__finderexternal_cycles';

	public function __construct(private readonly DatabaseInterface $db)
	{
	}

	public function load(int $taskId): ?Cycle
	{
		$query = $this->db->createQuery()
			->select('*')
			->from($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('task_id') . ' = :task')
			->bind(':task', $taskId, ParameterType::INTEGER);

		$row = $this->db->setQuery($query)->loadObject();

		if (!\is_object($row)) {
			return null;
		}

		$column = static fn (string $name): string => isset($row->{$name}) && \is_scalar($row->{$name}) ? (string) $row->{$name} : '';

		$state = json_decode($column('state'), true);
		$state = \is_array($state) ? $state : [];

		$sources = [];

		foreach (\is_array($state['sources'] ?? null) ? $state['sources'] : [] as $source) {
			if (\is_array($source) && \is_string($source['type'] ?? null) && \is_string($source['url'] ?? null)) {
				$sources[] = ['type' => $source['type'], 'url' => $source['url']];
			}
		}

		$robots = array_filter(\is_array($state['robots'] ?? null) ? $state['robots'] : [], 'is_string');
		$counts = array_map('intval', array_filter(\is_array($state['counts'] ?? null) ? $state['counts'] : [], 'is_numeric'));

		return new Cycle(
			$taskId,
			(int) $column('cycle'),
			$column('phase'),
			$sources,
			array_combine(array_map('strval', array_keys($robots)), array_values($robots)),
			array_combine(array_map('strval', array_keys($counts)), array_values($counts)),
			$column('started'),
			$column('finished')
		);
	}

	public function save(Cycle $cycle): void
	{
		$state    = (string) json_encode(['sources' => $cycle->sources, 'robots' => $cycle->robots, 'counts' => $cycle->counts]);
		$taskId   = $cycle->taskId;
		$number   = $cycle->number;
		$phase    = $cycle->phase;
		$started  = $cycle->started;
		$finished = $cycle->finished === '' ? null : $cycle->finished;

		$delete = $this->db->createQuery()
			->delete($this->db->quoteName(self::TABLE))
			->where($this->db->quoteName('task_id') . ' = :task')
			->bind(':task', $taskId, ParameterType::INTEGER);

		$insert = $this->db->createQuery()
			->insert($this->db->quoteName(self::TABLE))
			->columns($this->db->quoteName(['task_id', 'cycle', 'phase', 'state', 'started', 'finished']))
			->values(':task, :cycle, :phase, :state, :started, :finished')
			->bind(':task', $taskId, ParameterType::INTEGER)
			->bind(':cycle', $number, ParameterType::INTEGER)
			->bind(':phase', $phase)
			->bind(':state', $state)
			->bind(':started', $started)
			->bind(':finished', $finished, $finished === null ? ParameterType::NULL : ParameterType::STRING);

		$this->db->transactionStart();

		try {
			$this->db->setQuery($delete)->execute();
			$this->db->setQuery($insert)->execute();
			$this->db->transactionCommit();
		} catch (\Throwable $e) {
			$this->db->transactionRollback();

			throw $e;
		}
	}
}
