<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The manifest describes the package, and the package contains what it says.
 *
 * Both directions: everything the manifest lists exists, and every folder of
 * the plugin is listed. A folder that is there but not listed works on a
 * development site and is missing from every real install.
 */
final class PackageTest extends TestCase
{
    private const PLUGIN = '/src/plugins/task/finderexternal';

    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    private function manifest(): \SimpleXMLElement
    {
        $xml = simplexml_load_file($this->root() . '/src/finderexternal.xml');

        self::assertNotFalse($xml, 'The manifest is not valid XML.');

        return $xml;
    }

    public function testTheFilesFolderIsWhereThePluginIs(): void
    {
        self::assertSame(
            'plugins/task/finderexternal',
            (string) $this->manifest()->files['folder']
        );
    }

    public function testEveryFolderTheManifestClaimsIsThere(): void
    {
        $missing = [];

        foreach ($this->manifest()->files->folder as $folder) {
            if (!is_dir($this->root() . self::PLUGIN . '/' . $folder)) {
                $missing[] = (string) $folder;
            }
        }

        self::assertSame([], $missing);
    }

    public function testEveryFolderOfThePluginIsListed(): void
    {
        $listed = [];

        foreach ($this->manifest()->files->folder as $folder) {
            $listed[] = (string) $folder;
        }

        $unlisted = array_values(array_diff(
            array_filter(
                scandir($this->root() . self::PLUGIN) ?: [],
                fn (string $entry): bool => $entry[0] !== '.' && is_dir($this->root() . self::PLUGIN . '/' . $entry)
            ),
            $listed
        ));

        self::assertSame([], $unlisted);
    }

    public function testTheSqlFilesAndScriptAreThere(): void
    {
        $manifest = $this->manifest();

        foreach ([$manifest->install->sql->file, $manifest->uninstall->sql->file] as $files) {
            foreach ($files as $file) {
                self::assertFileExists($this->root() . self::PLUGIN . '/' . $file);
            }
        }

        foreach ($manifest->update->schemas->schemapath as $path) {
            self::assertFileExists($this->root() . self::PLUGIN . '/' . $path . '/' . $manifest->version . '.sql');
        }
        self::assertFileExists($this->root() . '/src/' . $manifest->scriptfile);
    }

    public function testTheTaskFormsAndLanguageFilesAreThere(): void
    {
        self::assertFileExists($this->root() . self::PLUGIN . '/forms/index.xml');
        self::assertFileExists($this->root() . self::PLUGIN . '/language/en-GB/plg_task_finderexternal.ini');
        self::assertFileExists($this->root() . self::PLUGIN . '/language/en-GB/plg_task_finderexternal.sys.ini');
    }

    public function testTheIniFilesParseAndUseNoQq(): void
    {
        foreach (glob($this->root() . self::PLUGIN . '/language/en-GB/*.ini') ?: [] as $file) {
            self::assertNotFalse(parse_ini_file($file, false, INI_SCANNER_RAW), basename($file));
            self::assertStringNotContainsString('_QQ_', (string) file_get_contents($file), basename($file));
        }
    }

    public function testEveryLanguageKeyTheFormsAndThePluginUseIsTranslated(): void
    {
        $ini  = (string) file_get_contents($this->root() . self::PLUGIN . '/language/en-GB/plg_task_finderexternal.ini');
        $form = (string) file_get_contents($this->root() . self::PLUGIN . '/forms/index.xml')
            . (string) file_get_contents($this->root() . self::PLUGIN . '/src/Extension/FinderExternal.php');
        $missing = [];

        preg_match_all('/PLG_TASK_LINKREPAIR_[A-Z_]+/', $form, $keys);

        foreach (array_unique($keys[0]) as $key) {
            // A task type's prefix is used with _TITLE and _DESC.
            $translated = preg_match('/^' . $key . '=/m', $ini)
                || (preg_match('/^' . $key . '_TITLE=/m', $ini) && preg_match('/^' . $key . '_DESC=/m', $ini));

            if (!$translated) {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing);
    }
}
