## ARCHITECT OUTPUT
- **Current Step (ROADMAP):** 2.1.1
- **Scope:** `composer.json`, `phpstan.neon` (new), `phpstan-baseline.neon` (new), `.php-cs-fixer.php`, all PHP files reformatted by PSR-12
- **Deferred:** [Step 2.1.2 - CI/CD integration for PHPStan/CS-Fixer quality gates], [Step 2.1.3 - `declare(strict_types=1)` migration; do NOT add strict_types rule to CS-Fixer], [Step 2.1.4+ - PHPStan level increases beyond 5]
- **Bridges:** None required — pure tooling installation and formatting, no behavioral changes
- **Checklist:**
  1. Install PHPStan and extensions: `composer require --dev phpstan/phpstan phpstan/phpstan-doctrine phpstan/phpstan-symfony`. Verify binary at `./app/vendor/bin/phpstan`.
  2. Create `phpstan.neon` in workspace root — Level 5, paths `app/modules`, `app/system`, `app/installer`, `app/console`, `packages`; exclude `app/vendor`, `app/modules/*/vendor`, `*/node_modules/*`, `tmp`, `storage`; bootstrap `app/vendor/autoload.php`; include Doctrine + Symfony extension `.neon` files. Verify paths match actual codebase layout.
  3. Run `./app/vendor/bin/phpstan analyse --generate-baseline` to create `phpstan-baseline.neon`. Add `phpstan-baseline.neon` to `includes` in `phpstan.neon`. Add comment at top of baseline file with total error count and date.
  4. Verify `./app/vendor/bin/phpstan analyse` passes with zero errors above baseline.
  5. Install security advisories: `composer require --dev roave/security-advisories:dev-latest`.
  6. Install PHP-CS-Fixer: `composer require --dev friendsofphp/php-cs-fixer`. Verify binary at `./app/vendor/bin/php-cs-fixer`.
  7. Update `.php-cs-fixer.php`: change `'@PSR2' => true` to `'@PSR12' => true`; remove `'packages'` from the `->exclude([...])` array. Do NOT add `declare_strict_types` rule (deferred: `// TODO: Must be refactored in Step 2.1.3 (strict_types Migration)`).
  8. Run `./app/vendor/bin/php-cs-fixer fix` to apply PSR-12 formatting across the codebase.
  9. Run `./app/vendor/bin/phpunit` — all ~280 tests must pass. If failures: investigate and fix formatting-induced regressions (formatting should not cause behavioral changes).
  10. Final validation: confirm all success criteria from task prompt — `phpstan.neon` exists with extensions, `phpstan-baseline.neon` exists with error count, PHPStan analyse clean, `.php-cs-fixer.php` uses `@PSR12` without `packages` exclusion, `roave/security-advisories` in `composer.json`, no `strict_types` changes, all tests green.
