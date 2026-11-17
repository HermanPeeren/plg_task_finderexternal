<?php

/**
 * The Joomla constants the plugin reads.
 *
 * Joomla defines these when an application boots, so they exist at runtime but
 * in no file an analyser reads. Only the ones this plugin uses are here.
 */

declare(strict_types=1);

// The Joomla version, which the install script checks.
\define('JVERSION', '6.2.0');

// Joomla's configuration.php, which tools/add-site-task.php loads from a site, declares this.
if (!class_exists('JConfig', false)) {
    // phpcs:ignore PSR1.Classes.ClassDeclaration.MissingNamespace
    class JConfig
    {
    }
}
