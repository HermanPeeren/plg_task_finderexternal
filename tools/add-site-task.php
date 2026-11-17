<?php

/**
 * Create (or update) a "Smart Search: index external site" task from a site profile.
 *
 *   php tools/add-site-task.php sites/manual.joomla.org.json            # into ./joomla
 *   php tools/add-site-task.php sites/magazine.joomla.org.json ../site
 *
 * A profile is the task's parameters as JSON, plus a title and a schedule; see sites/.
 * A task with the same title is updated instead of added, so a profile can be tuned and
 * applied again. The task is written straight into #__scheduler_tasks of a MySQL site,
 * as the Task Scheduler's own form would save it, so this needs no login. It is meant
 * for development sites and for setting up a site from the command line; on a live
 * site the form in System > Scheduled Tasks does the same.
 */

declare(strict_types=1);

$root    = \dirname(__DIR__);
$profile = $argv[1] ?? '';
$site    = $argv[2] ?? $root . '/joomla';

if ($profile === '' || !is_file($profile)) {
    fwrite(STDERR, "Usage: php tools/add-site-task.php <profile.json> [joomla-folder]\n");
    exit(1);
}

try {
    /** @var array{title?: string, params?: array<string, mixed>, schedule?: array{interval?: string, every?: int}} $data */
    $data = json_decode((string) file_get_contents($profile), true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, "{$profile} is not valid JSON: {$e->getMessage()}\n");
    exit(1);
}

$title  = trim((string) ($data['title'] ?? ''));
$params = $data['params'] ?? [];

if ($title === '' || $params === []) {
    fwrite(STDERR, "{$profile} needs a title and params.\n");
    exit(1);
}

$configuration = $site . '/configuration.php';

if (!is_file($configuration)) {
    fwrite(STDERR, "No Joomla configuration at {$configuration}.\n");
    exit(1);
}

require_once $configuration;

// Joomla's configuration.php declares the class JConfig; its properties are the settings.
$configClass = 'JConfig';
$config      = array_map(
    static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '',
    get_object_vars(new $configClass())
);

if (!\in_array($config['dbtype'] ?? '', ['mysqli', 'mysql'], true)) {
    fwrite(STDERR, "Only MySQL sites are supported by this tool; use the task form instead.\n");
    exit(1);
}

[$host, $port] = array_pad(explode(':', $config['host'] ?? 'localhost', 2), 2, '3306');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db    = new mysqli($host, $config['user'] ?? '', $config['password'] ?? '', $config['db'] ?? '', (int) $port);
$table = ($config['dbprefix'] ?? 'jos_') . 'scheduler_tasks';

$unit  = (string) ($data['schedule']['interval'] ?? 'hours');
$every = max(1, (int) ($data['schedule']['every'] ?? 6));
$map   = ['minutes' => 'PT%dM', 'hours' => 'PT%dH', 'days' => 'P%dD'];

if (!isset($map[$unit])) {
    fwrite(STDERR, "schedule.interval must be minutes, hours or days.\n");
    exit(1);
}

$now       = gmdate('Y-m-d H:i:s');
$execution = json_encode([
    'rule-type'          => 'interval-' . $unit,
    'interval-' . $unit  => (string) $every,
    'exec-day'           => gmdate('d'),
    'exec-time'          => gmdate('H:i'),
]);
$cron      = json_encode(['type' => 'interval', 'exp' => \sprintf($map[$unit], $every)]);
$json      = json_encode($params + ['individual_log' => false, 'log_file' => '', 'notifications' => []]);
$type      = 'finderexternal.index';

/**
 * A prepared statement; mysqli_report() makes failures throw, this makes that explicit.
 */
function statement(mysqli $db, string $sql): mysqli_stmt
{
    $statement = $db->prepare($sql);

    if ($statement === false) {
        throw new RuntimeException($db->error);
    }

    return $statement;
}

$find = statement($db, "SELECT id FROM `{$table}` WHERE title = ? AND type = ?");
$find->bind_param('ss', $title, $type);
$find->execute();
$result   = $find->get_result();
$existing = $result === false ? false : $result->fetch_column();

if ($existing !== false && $existing !== null) {
    $id     = (int) $existing;
    $update = statement($db, "UPDATE `{$table}` SET params = ?, execution_rules = ?, cron_rules = ? WHERE id = ?");
    $update->bind_param('sssi', $json, $execution, $cron, $id);
    $update->execute();

    echo "Updated task {$id}: {$title}\n";
    exit(0);
}

$insert = statement(
    $db,
    "INSERT INTO `{$table}` (title, type, execution_rules, cron_rules, state, next_execution, params, note, created, priority)"
    . " VALUES (?, ?, ?, ?, 1, ?, ?, '', ?, 0)"
);
$insert->bind_param('sssssss', $title, $type, $execution, $cron, $now, $json, $now);
$insert->execute();

echo "Added task {$db->insert_id}: {$title}\n";
echo "Run it with: php joomla/cli/joomla.php scheduler:run --id={$db->insert_id}\n";
