<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Settings;

use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Filesystem\Filesystem;
use Pagekit\System\Controller\SettingsController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The settings screen rewrites config.php while the site is serving from it, and
 * it rewrites the whole file - the connection and the secret it never showed the
 * administrator included. So the write has to arrive as one file rather than a
 * truncation another request reads, and a write that does not arrive at all has
 * to reach the screen as the failure it is instead of a green "success" over a
 * configuration that was never saved.
 */
final class SettingsControllerTest extends TestCase
{
    /**
     * A config.php as an installation leaves it: the key the screen edits, and
     * the connection and secret it must carry over untouched.
     */
    private const INSTALLED_CONFIG = <<<'PHP'
        <?php return [
            'application' => ['debug' => true],
            'database' => ['default' => 'sqlite'],
            'system' => ['secret' => 'not-a-real-secret'],
        ];
        PHP;

    /**
     * What the screen posts: one file setting and one database-backed option.
     */
    private const POSTED = [
        'config' => ['application' => ['debug' => false]],
        'options' => ['system' => ['site' => ['title' => 'Pagekit']]],
    ];

    private string $workspace;

    /**
     * The application root, which is where config.php sits.
     */
    private string $root;

    private string $configFile;

    protected function setUp(): void
    {
        // Composer maps Pagekit\System\ to app/system/src, so the controller -
        // which lives in the settings module - is not on the autoload map.
        require_once dirname(__DIR__, 3).'/app/system/modules/settings/src/Controller/SettingsController.php';

        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_settings_'.getmypid().'_'.uniqid();
        $this->root = $this->workspace.'/site';
        $this->configFile = $this->root.'/config.php';

        mkdir($this->root, 0755, true);
        file_put_contents($this->configFile, self::INSTALLED_CONFIG);
    }

    protected function tearDown(): void
    {
        // A test that provoked an unwritable configuration has to hand it back
        // before the workspace can be removed.
        if (is_file($this->configFile)) {
            chmod($this->configFile, 0644);
        }

        if (is_dir($this->root)) {
            chmod($this->root, 0755);
        }

        $this->removeTree($this->workspace);
    }

    public function testSavingRewritesTheEditedSettingsAndCarriesTheRestOfTheFileOver(): void
    {
        $options = new RecordedOptions();

        $result = $this->controller($options)->saveAction();

        self::assertSame(['message' => 'success'], $result);

        $written = require $this->configFile;

        self::assertIsArray($written);
        self::assertFalse($written['application']['debug']);
        self::assertSame('sqlite', $written['database']['default'], 'the connection the screen never showed survives the save');
        self::assertSame('not-a-real-secret', $written['system']['secret']);
        self::assertSame(['config.php'], $this->entries($this->root), 'nothing is left beside the file that was written');
    }

    public function testSavingStoresTheOptionsThatDoNotLiveInTheFile(): void
    {
        $options = new RecordedOptions();

        $this->controller($options)->saveAction();

        self::assertSame(['system' => ['site' => ['title' => 'Pagekit']]], $options->stored);
    }

    /**
     * The regression: the write used to be performed and its outcome dropped, so
     * a deployment that had made its application tree read-only got a saved
     * settings screen back over a config.php that had not changed.
     */
    public function testAConfigurationThatCannotBeWrittenFailsTheSaveInsteadOfReportingSuccess(): void
    {
        $this->hardenTheApplicationTree();

        $options = new RecordedOptions();

        $error = $this->failedSave($options);

        self::assertStringContainsString($this->configFile, $error->getMessage());
        self::assertSame(self::INSTALLED_CONFIG, file_get_contents($this->configFile), 'the installed configuration is left as it was');
        self::assertSame(['config.php'], $this->entries($this->root));
        // The options are stored after the file is written, so a failed write
        // must not leave half the settings applied either.
        self::assertSame([], $options->stored);
    }

    /**
     * Runs a save that must not succeed and returns the error it raised.
     */
    private function failedSave(RecordedOptions $options): \RuntimeException
    {
        try {
            $this->controller($options)->saveAction();
        } catch (\RuntimeException $error) {
            return $error;
        }

        self::fail('A configuration that could not be written must not be reported as saved.');
    }

    private function controller(RecordedOptions $options): SettingsController
    {
        return new SettingsController(new Request([], self::POSTED), $options, $this->configFile, new Filesystem());
    }

    /**
     * Takes the write permission off the configuration and the directory it sits
     * in - a tree only root could still write, which is how a hardened
     * deployment is handed to the web server.
     */
    private function hardenTheApplicationTree(): void
    {
        chmod($this->configFile, 0444);
        chmod($this->root, 0555);

        if (is_writable($this->configFile) || is_writable($this->root)) {
            self::markTestSkipped('The test user writes into a read-only tree on this host');
        }
    }

    /**
     * Lists what a directory holds, so a temp file left behind by the write shows
     * up as an unexpected entry.
     *
     * @return array<int, string>
     */
    private function entries(string $dir): array
    {
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($entries);

        return $entries;
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->removeTree($path.'/'.$entry);
        }

        rmdir($path);
    }
}

/**
 * Records the settings that are stored in the database rather than in the file,
 * in place of the manager only a connected installation has.
 *
 * A recorder rather than a mock: the save is run inside a catch for the error the
 * write raises, and a mock reports a violated expectation by throwing one that
 * would be caught there and read as the write's.
 */
final class RecordedOptions extends ConfigManager
{
    /** @var array<string, array<int|string, mixed>> */
    public array $stored = [];

    public function __construct()
    {
    }

    public function __invoke(string $name): ?Config
    {
        return null;
    }

    /**
     * @param Config|array<int|string, mixed> $config
     */
    public function set(string $name, Config|array $config): void
    {
        $this->stored[$name] = is_array($config) ? $config : $config->toArray();
    }
}
