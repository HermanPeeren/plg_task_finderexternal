<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Extension;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;
use Yepr\Plugin\Task\FinderExternal\Run\MissingConfiguration;
use Yepr\Plugin\Task\FinderExternal\Run\RoutineFactory;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Offers one task type to the Task Scheduler: "Smart Search: index external site".
 *
 * One task indexes one site. The work is in Crawler; this class hands it the task's
 * parameters and turns the outcome into an exit code: OK when the cycle is done,
 * WILL_RESUME when the time budget ran out with work left, so the scheduler runs the
 * task again on its next tick and it goes on where it stopped.
 */
final class FinderExternal extends CMSPlugin implements SubscriberInterface
{
	use TaskPluginTrait;

	/**
	 * @var array<string, array<string, string>>
	 */
	protected const TASKS_MAP = [
		'finderexternal.index' => [
			'langConstPrefix' => 'PLG_TASK_FINDEREXTERNAL_INDEX',
			'form'            => 'index',
			'method'          => 'index',
		],
	];

	/**
	 * @var boolean
	 */
	protected $autoloadLanguage = true;

	/**
	 * @param   array<string, mixed>  $config  The plugin's row, from PluginHelper::getPlugin().
	 */
	public function __construct(array $config, private readonly RoutineFactory $routines)
	{
		parent::__construct($config);
	}

	/**
	 * @return  array<string, string>
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onTaskOptionsList'    => 'advertiseRoutines',
			'onExecuteTask'        => 'standardRoutineHandler',
			'onContentPrepareForm' => 'enhanceTaskItemForm',
		];
	}

	/**
	 * The index routine.
	 *
	 * @return  integer  A TaskStatus exit code.
	 */
	protected function index(ExecuteTaskEvent $event): int
	{
		$params = $event->getArgument('params');

		try {
			$crawler = $this->routines->crawler(\is_object($params) ? $params : (object) [], function (string $message, string $priority): void {
				$this->logTask($message, $priority);
			});
		} catch (MissingConfiguration $e) {
			$this->logTask(\sprintf(Text::_('PLG_TASK_FINDEREXTERNAL_LOG_NOT_CONFIGURED'), $e->getMessage()), 'error');

			return TaskStatus::KNOCKOUT;
		}

		try {
			return $crawler->run($event->getTaskId())->finished ? TaskStatus::OK : TaskStatus::WILL_RESUME;
		} catch (\RuntimeException $e) {
			$this->logTask(\sprintf(Text::_('PLG_TASK_FINDEREXTERNAL_LOG_FAILED'), $e->getMessage()), 'error');

			return TaskStatus::KNOCKOUT;
		}
	}
}
