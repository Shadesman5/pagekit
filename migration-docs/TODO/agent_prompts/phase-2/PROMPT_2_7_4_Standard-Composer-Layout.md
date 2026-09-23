# Step 2.7.4: Standard Composer Layout (`vendor/` at root)

<!-- conductor-mode: full -->

**ROADMAP:** 2.7.4. GitHub Issue: #271. Reference: `@ROADMAP.md`, `PHASE_2_MODERNISING.md` §2.7.4.

---

## Task

This project's Composer dependencies live in `./vendor`, binaries in `./vendor/bin`, and `path.vendor` names that one directory. The custom vendor-directory setting is gone. Nothing reads `app/vendor` when `./vendor` is missing.

`composer install` writes `./vendor`. The root autoloader, the container path, Docker image copies, and CI Composer caches use that directory. PHPUnit, PHPStan, CS-Fixer, and every documented binary path are `./vendor/bin/…`.

A reader that still assumes the vendor directory lives under `app/` moves with it. Nothing keeps writing or packing `app/vendor`.

This is layout. Application behaviour stays as it is once the path is the same everywhere.

## Findings

**Where it is written.** `composer.json` sets `vendor-dir` to `app/vendor`. `autoload.php` requires `app/vendor/autoload.php`. `public/index.php` sets `path.vendor` to `$path.'/app/vendor'`. `.gitignore` ignores `/app/vendor/*`.

**Who else names that directory.** `tests/bootstrap.php`. `phpunit.xml.dist` and `phpunit-mysql.xml.dist` (the schema location and the excluded directory). `infection.json.dist` (`customPath`). The Composer cache path in the PHP and E2E workflows. `Dockerfile` copies `app/vendor` out of the Composer stage. `AGENTS.md` documents `app/vendor` as the vendor directory.

**A hardcoded fallback.** `PackageManager`, when the container has no `path.temp`, sets `path.vendor` from the class file: two directories up, then `/app/vendor`. From `app/package/src` that is `app/app/vendor`.

## Out of scope

- The extension package contract, author tooling, and upload archives.
- Release automation and the updater. A path this tree still reads as `app/vendor` is in scope. Building the update flow is not.
- Renaming the `app/` directory.
- Splitting the repository.

## Done when

- `composer install` writes `./vendor`. `app/vendor` does not exist and nothing creates it.
- `path.vendor` is that directory. No fallback names `app/vendor`.
- `./vendor/bin/phpunit` and `./vendor/bin/phpstan` are the binaries the suite and the docs use.
- Docker image copies and CI Composer caches use `./vendor`.
- No artefact still packs `app/vendor`.
