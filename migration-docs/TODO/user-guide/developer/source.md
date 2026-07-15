# Install Pagekit from source

<p class="uk-article-lead">When building Pagekit extensions, you can totally rely on a Pagekit installation from one of the release packages available at [pagekit.com](https://www.pagekit.com/download) or [from Github](https://github.com/pagekit/pagekit/releases).</p>

However, if you always want stay up to date with the current development version, you can install Pagekit from the source available on Github. This article explains, which steps you need to take.

**Note** Pagekit is built on Symfony 6.4 LTS and follows PSR standards (PSR-6 Cache, PSR-11 Container). Make sure your development environment meets the [system requirements](../getting-started/requirements.md), including PHP 8.2+. Composer installs PHP dependencies into `app/vendor/` (set via `config.vendor-dir` in `composer.json`).

<ul class="uk-list">
    <li><a href="#check-out-and-install">Check out and install</a></li>
    <li><a href="#stay-up-to-date">Stay up to date</a></li>
</ul>

## Check out and install

Make sure that [Composer](https://getcomposer.org/doc/00-intro.md#installation-nix) and [npm](https://www.npmjs.com/) are installed.

Clone the repository:

```bash
git clone --branch develop https://github.com/Shadesman5/pagekit.git
```

Navigate to the cloned directory and install PHP dependencies:

```bash
composer install
```

Install Node dependencies and build the front-end assets:

```bash
yarn install
```

To watch for LESS asset changes during development, run `yarn watch-less`. To watch for JavaScript module changes, run `yarn watch-js`. Use `yarn watch-all` to run both watchers in parallel.

When the installer has finished, point your browser to the Pagekit URL on your web server and follow the installer.

When you have a running Pagekit installation, use the Pagekit CLI to fetch translations. Without that, the interface will appear in English only.

```
php pagekit translation:fetch
```

## Stay up to date

If you've set up Pagekit from source, run these commands to fetch new commits and rebuild dependencies:

```bash
git pull
composer install
yarn install
```