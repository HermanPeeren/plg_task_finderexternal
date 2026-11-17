<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Http;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * A request got no response: DNS, timeout, refused or reset connection.
 */
final class HttpFailure extends \RuntimeException
{
}
