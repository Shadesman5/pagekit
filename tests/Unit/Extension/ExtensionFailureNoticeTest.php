<?php

declare(strict_types=1);

namespace Pagekit\Tests\Unit\Extension;

use Pagekit\Application;
use Pagekit\Filesystem\Filesystem;
use Pagekit\Session\MessageBag;
use Pagekit\System\Extension\ExtensionFailureStore;
use Pagekit\View\Event\ViewEvent;
use PHPUnit\Framework\TestCase;

/**
 * How an administrator finds out that a package was switched off for them.
 *
 * The request that ran into the failure is almost never theirs - a visitor hit
 * the broken extension, and the flash message that request could have queued
 * would have been spent on that visitor. So nothing is queued: the notice is
 * derived from the durable record on every admin render, which makes it stand
 * for as long as the failure does and disappear the moment the record is
 * cleared, with no session state that could expire or linger.
 *
 * The panel this renders in is the one the site is repaired from, so deriving
 * the notice may not be able to break it. Asking who is looking means reading
 * permissions from the database, which is a plausible thing for the failing
 * extension to have taken down, and that question is therefore both asked last
 * and allowed to fail.
 *
 * The handler lives in the system module's index.php, which is a module
 * definition rather than an autoloaded class: it is pulled in below so its
 * closure binds to the container built in the same scope.
 */
final class ExtensionFailureNoticeTest extends TestCase
{
    /**
     * The permission that decides who is told. Anyone who cannot install or
     * remove packages cannot act on the failure either.
     */
    private const CAPABILITY = 'system: manage packages';

    private string $workspace;

    /**
     * Where the record the notice is read from lives.
     */
    private string $path;

    /**
     * The services the render resolved, in order, so a question that is asked
     * where it should not have been shows up.
     *
     * @var array<int, string>
     */
    private array $resolved = [];

    protected function setUp(): void
    {
        $this->workspace = strtr(sys_get_temp_dir(), '\\', '/').'/pk_failure_notice_'.getmypid().'_'.uniqid();
        $this->path = $this->workspace.'/system';

        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);
    }

    public function testAnExtensionThatWasSwitchedOffIsNamedInTheAdminPanel(): void
    {
        $this->record('blog', ExtensionFailureStore::TYPE_EXTENSION);

        // One direct child of the wrapper per failure, carrying the status the
        // admin scripts turn it into a notification with. A warning is not the
        // status they let expire early, which is what a standing failure needs.
        self::assertSame(
            '<div class="pk-system-messages">'
            .'<div class="uk-alert uk-alert-warning" data-status="warning">'
            .'The extension "blog" failed and was disabled. See the error log for details.'
            .'</div></div>',
            $this->render(),
        );
    }

    public function testAThemeIsReportedAsUnusableRatherThanAsSwitchedOff(): void
    {
        $this->record('theme-one', ExtensionFailureStore::TYPE_THEME);

        // The selected theme is an administrator's decision and stays selected;
        // only the layout falls back. Telling them it was disabled would send
        // them looking for a setting nothing changed.
        self::assertSame(
            '<div class="pk-system-messages">'
            .'<div class="uk-alert uk-alert-warning" data-status="warning">'
            .'The theme "theme-one" could not be loaded. See the error log for details.'
            .'</div></div>',
            $this->render(),
        );
    }

    public function testTheNoticeNamesTheExtensionWithoutRepeatingWhatItFailedWith(): void
    {
        $this->record('blog', ExtensionFailureStore::TYPE_EXTENSION, new \RuntimeException('Table "pk_blog_post" not found'));

        $messages = $this->render();

        // The notice names the extension and points at the log. Rendering the
        // message a failure carried would put a database error, a filesystem
        // path or whatever else it picked up into an admin page.
        self::assertStringContainsString('blog', $messages);
        self::assertStringContainsString('error log', $messages);
        self::assertStringNotContainsString('pk_blog_post', $messages);
    }

    public function testEveryFailureOnRecordIsNamedRatherThanJustTheFirst(): void
    {
        $this->record('blog', ExtensionFailureStore::TYPE_EXTENSION);
        $this->record('theme-one', ExtensionFailureStore::TYPE_THEME);

        $messages = $this->render();

        // A theme taking the site down and an extension taking the site down
        // tend to arrive together, and fixing one of the two is not the job.
        self::assertSame(2, substr_count($messages, 'data-status="warning"'));
        self::assertStringContainsString('The extension "blog"', $messages);
        self::assertStringContainsString('The theme "theme-one"', $messages);
    }

    public function testTheStandingNoticeAppearsBesideTheMessagesOfTheRequestItself(): void
    {
        $this->record('blog', ExtensionFailureStore::TYPE_EXTENSION);

        $messages = $this->render(flash: ['success' => ['Settings saved.']]);

        // Whatever the administrator just did still gets its answer: the notice
        // is added to the messages of this request, not put in their place.
        self::assertSame(
            '<div class="pk-system-messages">'
            .'<div class="uk-alert uk-alert-success" data-status="success">Settings saved.</div>'
            .'<div class="uk-alert uk-alert-warning" data-status="warning">'
            .'The extension "blog" failed and was disabled. See the error log for details.'
            .'</div></div>',
            $messages,
        );
    }

    public function testAVisitorIsNotToldAndIsNotEvenLookedUp(): void
    {
        $this->record('blog', ExtensionFailureStore::TYPE_EXTENSION);

        // The front end of the site says nothing about which of its extensions
        // is broken: that is an operational detail, and the visitor can do
        // nothing with it. Nothing is read to establish that, either.
        self::assertSame('<div class="pk-system-messages"></div>', $this->render(isAdmin: false));
        self::assertSame([], $this->resolved);
    }

    public function testAnAdministratorWhoCannotManagePackagesIsNotTold(): void
    {
        $this->record('blog', ExtensionFailureStore::TYPE_EXTENSION);

        // An editor in the panel cannot enable, disable or remove anything, so
        // the notice would be a permanent alarm they have no answer to.
        self::assertSame('<div class="pk-system-messages"></div>', $this->render(canManagePackages: false));
    }

    public function testWhoIsLookingIsOnlyEstablishedWhenThereIsSomethingToReport(): void
    {
        // Answering it means reading permissions from the database, and on a
        // site whose database is what broke that read would fail on every admin
        // render. With an empty record it is a question with no consequence.
        self::assertSame('<div class="pk-system-messages"></div>', $this->render());
        self::assertSame(['extension.failures'], $this->resolved);
    }

    public function testThePanelStillRendersWhenTheDatabaseCannotSayWhoIsLooking(): void
    {
        $this->record('blog', ExtensionFailureStore::TYPE_EXTENSION);

        $messages = $this->render(
            flash: ['error' => ['Could not save.']],
            accessFails: new \RuntimeException('An exception occurred while executing a query'),
        );

        // This renders in the panel the site is put back together from. Losing
        // the notice costs one failure nobody was told about; a throw escaping
        // here would cost the page that failure has to be repaired from.
        self::assertSame(
            '<div class="pk-system-messages">'
            .'<div class="uk-alert uk-alert-danger" data-status="danger">Could not save.</div>'
            .'</div>',
            $messages,
        );
    }

    public function testAModuleNameIsEscapedBeforeItIsRendered(): void
    {
        // The name comes from a package's own composer.json, and it is rendered
        // into the page of the one account that can install packages.
        $this->record('"><script>alert(1)</script>', ExtensionFailureStore::TYPE_EXTENSION);

        $messages = $this->render();

        self::assertStringNotContainsString('<script>', $messages);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $messages);
    }

    public function testAnInstallationThatKeepsNoRecordRendersTheMessagesItAlwaysDid(): void
    {
        // The installer boots the system module against a container that has no
        // record service, and the messages of the installation itself are the
        // only thing that screen has to show.
        $messages = $this->render(flash: ['info' => ['Installing Pagekit.']], withStore: false);

        self::assertSame(
            '<div class="pk-system-messages">'
            .'<div class="uk-alert uk-alert-info" data-status="info">Installing Pagekit.</div>'
            .'</div>',
            $messages,
        );
        self::assertSame([], $this->resolved);
    }

    /**
     * Renders the system messages of one admin request and hands back the
     * markup the layout receives.
     *
     * @param array<string, array<int, string>> $flash the messages the previous request left behind
     * @param \Throwable|null                   $accessFails what the permission check fails with, when it does
     */
    private function render(
        array $flash = [],
        bool $isAdmin = true,
        bool $canManagePackages = true,
        ?\Throwable $accessFails = null,
        bool $withStore = true,
    ): string {
        $app = new Application();
        $app->set('isAdmin', $isAdmin);
        $app->set('message', $this->messages($flash));

        if ($withStore) {
            $app->set('extension.failures', function (): ExtensionFailureStore {
                $this->resolved[] = 'extension.failures';

                return $this->store();
            });
        }

        // The permission is compared rather than ignored: asking for one nobody
        // holds would make the notice quietly stop appearing.
        $capability = self::CAPABILITY;

        $app->set('user', function () use ($canManagePackages, $accessFails, $capability): object {
            $this->resolved[] = 'user';

            return new class ($canManagePackages, $accessFails, $capability) {
                public function __construct(
                    private readonly bool $granted,
                    private readonly ?\Throwable $fails,
                    private readonly string $capability,
                ) {
                }

                public function hasAccess(string $capability): bool
                {
                    if ($this->fails !== null) {
                        throw $this->fails;
                    }

                    return $this->granted && $capability === $this->capability;
                }
            };
        });

        /** @var array{events: array{'view.messages': callable}} $module */
        $module = require dirname(__DIR__, 3).'/app/system/index.php';

        $event = new ViewEvent('view.messages', null);
        $module['events']['view.messages']($event);

        return (string) $event->getResult();
    }

    /**
     * The message bag as a request receives it: what was queued during the
     * previous request is what this one displays.
     *
     * @param array<string, array<int, string>> $flash
     */
    private function messages(array $flash): MessageBag
    {
        $session = ['new' => $flash];

        $bag = new MessageBag();
        $bag->initialize($session);

        return $bag;
    }

    /**
     * Puts a failure on record, the way the boot that ran into it does.
     */
    private function record(string $name, string $type, ?\Throwable $failure = null): void
    {
        self::assertTrue(
            $this->store()->record($name, $type, $failure ?? new \RuntimeException('The module could not be loaded')),
            'The notice is derived from the record, so the record has to be on disk before the render',
        );
    }

    private function store(): ExtensionFailureStore
    {
        return new ExtensionFailureStore($this->path, new Filesystem());
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
