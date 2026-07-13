# File Structure
<p class="uk-article-lead">When you get started using Pagekit, it is important that you know your way around the file structure. As Pagekit has a very clear separation of core code and third party files, this shouldn't be a big deal.</p>

## Explanation video

The following video goes through the structure and explains everything you need to know.

<iframe class="uk-responsive-width" width="1280" height="720" src="https://www.youtube-nocookie.com/embed/-cH53Hq7F4o" frameborder="0" allowfullscreen></iframe>

## Compact overview

For a brief overview, have a look at the following listing.

```
/app                      // main system files
  assets                  // system assets
  config                  // service-wiring and module-load order
  console                 // CLI commands
  installer               // core Install/Update extension files
  migrations              // core Doctrine Migrations (organised by year, e.g. 2025/)
  modules                 // core modules; each module has its own subfolder
  scripts                 // build and maintenance scripts
  system                  // core System extension files
  vendor                  // Composer vendor directory (composer.json sets vendor-dir to app/vendor)
/packages                 // Pagekit packages and 3rd party packages
  composer                // packager related files
  pagekit                 // Pagekit default packages
    blog                  // default Blog extension
    theme-one             // the default theme distributed with Pagekit
/storage                  // site media files (writable). Configurable in System > Settings
/tmp                      // temporary files (writable)
  cache                   // PSR-6 cache pool storage and compiled files
  logs                    // application log files
  packages                // temporary package files used during install / update
  sessions                // file-based user sessions
  temp                    // general temporary files
.htaccess                 // Apache configuration. Required when serving via Apache
CHANGELOG.md              // changelog file
composer.json             // Composer manifest. config.vendor-dir = app/vendor
composer.lock             // committed dependency lock file
config.php                // configuration file generated during the installation
pagekit                   // CLI entry point (php pagekit ...)
pagekit.db                // SQLite database file (only when using the SQLite driver)
```

**Note** Composer installs all PHP dependencies into `/app/vendor` rather than the conventional `/vendor` at the project root. This is set via `"config": {"vendor-dir": "app/vendor"}` in `composer.json`.

The `/tmp` and `/storage` directories must be writable by the web server. On a fresh checkout, create them with their expected subdirectories:

```bash
mkdir -p tmp/cache tmp/logs tmp/temp tmp/packages tmp/sessions storage
```

## Places to explore

While it always takes some getting used to a new project's structure, you will quickly find your way around the important parts. The essential thing to know is that themes and extensions that you develop always sit in the `/packages` directory, inside a subfolder with your vendor name.

Additionally, it is a good idea to have a look at the official packages located in `/packages/pagekit` - for inspiration and a deeper understanding of the Pagekit concepts. Also, check out the modules in `/app/modules` and `/app/system/modules` to see examples of what can be done with the module pattern.
