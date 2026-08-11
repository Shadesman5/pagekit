<?php

declare(strict_types=1);

namespace Pagekit\System\Extension;

use Pagekit\Module\ModuleManager;
use Psr\Log\LoggerInterface;

/**
 * The barrier around loading the site's extensions and its theme.
 *
 * A module's main() is the extension's own code: it registers services, hangs
 * listeners on events, and can do anything else PHP allows - including fail.
 * Unhandled, that failure is the whole site, on every request, over one package
 * an administrator may not even remember installing. Here it costs the package.
 *
 * What a caught failure costs exactly, because it is not nothing: whatever the
 * failing main() registered in the container before it threw stays there for the
 * rest of this request. Nothing records what a module put into the container, so
 * there is nothing to take back out. The module itself is never registered as
 * loaded, so nothing can resolve it as one, and the request finishes degraded
 * but coherent. The next request does not execute the extension at all - it is
 * taken out of the enabled list and written to the failure record here. Half an
 * extension for the request that broke is the price; half an extension on every
 * request after it would be the bug.
 *
 * The record is also what makes that stick when the site cannot help itself: a
 * failure whose recovery never reached the database still leaves the record
 * behind, and the record is read before the first module is loaded. An
 * extension on it is left out of the load list without a line of its own - the
 * failure that put it there was written down once, and repeating it on every
 * request afterwards would only bury it.
 *
 * The theme goes through the same barrier and leaves it differently. Which theme
 * a site uses is an administrator's setting, not a decision this class may make,
 * and a site whose theme cannot be loaded already falls back to a blank layout
 * while the admin panel keeps a theme of its own. A failing theme is logged and
 * recorded; it is never switched off, and it is tried again on the next request.
 * Being tried again is also how it gets off the record: a theme that loads is a
 * theme that is no longer broken, and here is the only place that can tell.
 */
final class ExtensionLoader
{
    /**
     * @param ExtensionFailureStore|null $failures where a failure is kept for the next boot, or
     *                                             null in a container that names no place to keep one
     * @param \Closure(string): void     $disable  takes an extension out of the site configuration
     */
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly LoggerInterface $logger,
        private readonly ?ExtensionFailureStore $failures,
        private readonly \Closure $disable,
    ) {
    }

    /**
     * Loads what the site is configured to run, one failure at a time.
     *
     * @param array<int, string> $extensions the module names the site configuration enables
     * @param string|null        $theme      the module name of the site theme, if one is set
     */
    public function load(array $extensions, ?string $theme): void
    {
        $this->reportRegistrationFailures();

        // Read before the first module runs: an extension that is already on
        // record is not executed again, whether or not the failure that put it
        // there also got as far as the configuration.
        $recorded = $this->failures?->all() ?? [];

        foreach (array_diff($extensions, array_keys($recorded)) as $name) {
            $this->loadModule($name, ExtensionFailureStore::TYPE_EXTENSION);
        }

        if ($theme === null) {
            return;
        }

        // An extension on the record is never executed, so a record is only
        // ever cleared by an administrator acting on the package. The theme is
        // executed regardless, which makes it the one module that can be on the
        // record and working at the same time - and nothing else on this path
        // would notice. Left there, the record would keep the theme named as
        // broken in the admin panel until some unrelated package operation
        // happened to clear it.
        if ($this->loadModule($theme, ExtensionFailureStore::TYPE_THEME) && isset($recorded[$theme])) {
            $this->clearFailure($theme);
        }
    }

    /**
     * Hands the failures discovery collected to the log.
     *
     * Discovery runs before the first module is loaded, so a package that could
     * not be executed has had nowhere to be reported until now. It is reported
     * by path, which is all a file that never declared a name leaves behind, and
     * before anything is loaded, so that an extension the site still enables out
     * of that same file - which reads as an undefined module a moment later -
     * reads as the consequence it is. That second half is where such an
     * extension is disabled and recorded, under the name the site configuration
     * is the last place to still know it by.
     */
    private function reportRegistrationFailures(): void
    {
        foreach ($this->modules->getRegistrationFailures() as $file => $error) {
            $this->report(sprintf('Extension failure [%s] during registration: %s', $file, $error->getMessage()), $error);
        }
    }

    /**
     * @param  ExtensionFailureStore::TYPE_* $type
     * @return bool                          whether the module ran its own code to the end
     */
    private function loadModule(string $name, string $type): bool
    {
        try {
            $this->modules->load($name);

            return true;
        } catch (\Throwable $e) {
            $this->fail($name, $type, $e);

            return false;
        }
    }

    /**
     * Everything that happens to a module that could not be loaded: it goes into
     * the log with its trace, out of the configuration if it is an extension, and
     * onto the record either way.
     *
     * The three are independent on purpose. What broke may be the database, so
     * the record is written whether or not the extension could be disabled - it
     * is the one thing that keeps the extension off on the next boot. Trouble
     * with any of them is reported on its own and never replaces the failure all
     * three are here to report.
     *
     * @param ExtensionFailureStore::TYPE_* $type
     */
    private function fail(string $name, string $type, \Throwable $e): void
    {
        $this->report(sprintf('Extension failure [%s] during load: %s', $name, $e->getMessage()), $e);

        if ($type === ExtensionFailureStore::TYPE_EXTENSION) {
            $this->disableExtension($name);
        }

        $this->recordFailure($name, $type, $e);
    }

    private function disableExtension(string $name): void
    {
        try {
            ($this->disable)($name);
        } catch (\Throwable $e) {
            $this->report(sprintf('Extension [%s] failed and could not be disabled: %s', $name, $e->getMessage()), $e);
        }
    }

    /**
     * @param ExtensionFailureStore::TYPE_* $type
     */
    private function recordFailure(string $name, string $type, \Throwable $e): void
    {
        if ($this->failures === null) {
            return;
        }

        if (!$this->failures->record($name, $type, $e)) {
            $this->report(sprintf('The failure of [%s] could not be recorded, so the next boot will run it again.', $name));
        }
    }

    /**
     * Takes a module off the record now that it has loaded.
     *
     * A record that cannot be cleared is reported and nothing more: the module
     * is running, and the boot it is part of will not be stopped over a notice
     * that stays up too long.
     */
    private function clearFailure(string $name): void
    {
        if ($this->failures === null || $this->failures->clear($name)) {
            return;
        }

        $this->report(sprintf('[%s] loaded again but could not be taken off the failure record, so it stays named as broken.', $name));
    }

    /**
     * Writes one line to the log, which is the last place a failure can be
     * reported to and therefore the last thing allowed to raise one.
     */
    private function report(string $message, ?\Throwable $e = null): void
    {
        try {
            $this->logger->error($message, $e !== null ? ['exception' => $e] : []);
        } catch (\Throwable) {
            // A log that cannot be written to costs the report. Throwing over it
            // would cost the boot this whole class exists to finish.
        }
    }
}
