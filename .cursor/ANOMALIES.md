# ANOMALIES

Anti-pattern catalogue. Patterns, never incidents — an entry has to outlive its own fix.

Maintained by hand. Agents read this file; they never write it.

## Conventions

- IDs are stable: never renumber, never reuse.
- No metadata: no PR numbers, commit hashes, line numbers, dates or counts. The pattern, not the incident.
- Developer comments stay verbatim, in the language they were written in.
- `Developer` quotes the maintainer verbatim; `Found in review` is a finding reconstructed from the history without its original wording. Never file one under the other.
- `Fix` says whose call it is. **In scope** — the author of the step fixes it; a reviewer that meets it fails the step and quotes the entry, ID and Rule, so the author can act on it without having read this file. **Escalate** — not the author's call: the plan decides it; a reviewer that meets it reports an escalation, not a fix request, and the step stops.
- Escalate whenever the fix would move a module boundary, change a contract that code outside this tree calls (extension- and theme-facing API, platform helpers), or go beyond what the plan authorised. An internal signature is none of these: change it and update every call site.
- A candidate entry goes to the maintainer — PR body or branch doc — never into this file.

---

## AP-01 — Compatibility bridge without an expiry date

```php
if (!method_exists($this, 'oldMethod')) {
    return $this->legacyBridge();
}
```

**Developer:** „Unverständlicher Zeilencode, was bringt das? Warum wird hier eine Brücke gebaut, obwohl die aufgerufene Klasse in Schritt 2.0 vollständig modernisiert wurde?“

**Rule:** No shim, wrapper or compat class to keep an old calling pattern alive. Migrate every call site to the new signature.

**Fix:** In scope — update the call sites.

---

## AP-02 — `mixed` without a stated reason

```php
public function handleData(mixed $payload): mixed { ... }
```

**Developer:** „Hier wird zu leichtfertig `mixed` verwendet, obwohl der Typ zur Laufzeit klar definiert ist (z. B. `array` oder string-basierte DTOs).“

**Rule:** `mixed` is allowed where it is named: generic container, PSR-11 `get()`, magic method, inherited contract, polymorphic library return, `callable` property (PHP has no native type for it). Everywhere else, narrow it.

**Fix:** In scope — narrow the type, or state the reason at the signature.

---

## AP-03 — Optional container lookup hiding an undeclared module dependency

```php
use Pagekit\System\Extension\ExtensionFailureStore;

// module manifest declares no require on that module
$store = $this->app->has('extension.failures') ? $this->app->get('extension.failures') : null;
```

**Found in review:** The runtime lookup is defensible; the hard import across a boundary no manifest declares is not.

**Rule:** `has()`/`get()` is for a service that is genuinely absent in some environments. If the class cannot do its job without it, or the import crosses a module boundary the manifest does not declare, the dependency is real.

**Fix:** In scope — declare it. Escalate if declaring it would create a cycle: then the class is in the wrong module.

---

## AP-04 — Placement by proximity instead of by concern

```php
// whole-database dump/restore engine, filed where the caller happened to live
namespace Pagekit\Installer\Package\Snapshot;

final class DatabaseRestorer { ... }
```

**Developer:** „ich glaube einige dateien sind zu land geraten, oder? - wie zum beispiel die 'app/installer/src/Package/PackageManager.php'. liegt es an der spezifischen architektur, oder könnte man das besser machen?“

**Rule:** Length is the symptom, not the defect: a file grows because concerns are filed where their caller happens to live, and splitting it inside the same module keeps the wrong boundary. A module name is a contract, not a folder. Before adding a subsystem or a second-level namespace to an existing module, name that module's concern in one sentence and check the fit.

**Fix:** Escalate — where a concern lives is an architecture decision, not a file move.

---

## AP-05 — `mixed` narrowed into a lie

```php
// level target satisfied, contract broken: preg_replace returns string|array|null
public function filter(string $value): string { ... }
```

**Found in review:** A concrete type would simply be wrong in most of the places that still carry `mixed`.

**Rule:** A level target is no reason to name a type the code cannot guarantee. Where the value genuinely can be anything, `mixed` plus a docblock naming why is the correct answer, and AP-02 does not apply.

**Fix:** In scope — restore `mixed` and state the reason at the signature.

---

## AP-06 — The gate is silenced instead of the cause removed

```neon
# phpstan-baseline.neon — kept so the level target passes
- message: '#implicitly nullable#'
  count: 3
  path: app/installer/src/Helper/InstallerIO.php
```

**Found in review:** An advisory no rebuild can close is a reason to drop the dependency, not to waive the finding.

**Rule:** A baseline or ignore entry is not a fix. It is admissible only where no change to this tree can clear the finding, and then it carries a reason and a review date. Green by suppression is red.

**Fix:** In scope — fix the cause and delete the entry. Escalate if the cause is a dependency that has to go.

---

## AP-07 — A green unit suite taken as proof of no regression

```php
// removed with the DI refactor; every related(...)->get() threw from here on
$query = $targetEntity::query();
```

**Found in review:** The suite stayed green because the test only constructed the object; nothing ever ran the path.

**Rule:** A refactor that removes a call path is proven at the path, not at the type. Signature-level tests, a passing suite and clean static analysis all survive a dead path. Boot the app, run the request, do the install.

**Fix:** In scope — exercise the path and add the test that was missing.

---

## AP-08 — A name that promises a guarantee the code does not give

```php
public function dumpAtomic(string $file, string $content): void
{
    // rename() path, and on failure silently:
    file_put_contents($file, $content, LOCK_EX);
}
```

**Found in review:** A fallback that drops the guarantee in the name is worse than no guarantee, because the caller stops checking.

**Rule:** Where the name states a guarantee, the code holds it or throws. No silent degradation, and no generic message replacing the reason the layer below gave.

**Fix:** In scope — remove the fallback, or rename the thing.

---

## AP-09 — A rebuildable artefact that takes the request down

```php
// routes dump caught half-written by a concurrent regeneration; the request died with it
return new CompiledUrlMatcher(require $cacheFile, $this->context);
```

**Found in review:** Everything needed to answer without the dump was already loaded; the reader threw instead of using it.

**Rule:** Mirror of AP-08: a write that promises a guarantee throws, a read of something derived does not. A dump, compiled cache or generated registry that can be rebuilt from state already at hand is never a reason to fail the request — on a torn or missing read, fall back to the source or regenerate. Throw only where the artefact is the only copy of the state.

**Fix:** In scope — catch at the read, degrade to the uncached path, and pin the torn-file case with a test.

---

## AP-10 — An inherited secret treated as rotatable

```php
// widget imported from upstream; the key arrived with the sources
'appid' => '<upstream project key, not ours to revoke>',
```

**Found in review:** The key is in upstream history too, so no rewrite of this history changes its exposure and nobody here can revoke it.

**Rule:** For a credential this project did not issue, rotation is not an available action and neither is a history rewrite. The available answer is to stop using it — which means removing the feature that needs it.

**Fix:** Escalate — dropping a feature is a product decision.

---

## AP-11 — A tool's findings read as the whole defect class

```php
// $view->script() took `array $dependencies`; the hits in index.php files were fixed,
// the ones in views/*.php were not — each a TypeError on a rendered page
$view->script('comments', 'blog:app/bundle/comments.js', 'vue');
```

**Found in review:** Static analysis reads `src/` and the suite renders no template, so the templates are exactly where the misses survive.

**Rule:** A PHPStan error or a review comment is one sample of a defect class, never the list. After narrowing a signature, grep the tree for the old shape — `views/`, widgets, themes, packages included — and close it in one commit.

**Fix:** In scope — sweep the class, then re-grep to prove it is empty.

---

## AP-12 — A gate that was born blind

```yaml
# audits an empty installed set: no install step, no --locked
- run: composer audit
```

**Found in review:** Green for months against a frozen lockfile; the first run that inspected anything was red across the dependency set.

**Rule:** A new or changed gate must be shown capable of failing: run it against known-bad input, confirm the non-zero exit, and state how much it inspected. The same holds for a spec whose target is not in the tree and for a note that promises an exclusion nobody created — a control that cannot fail is not a control. Never answer a red gate by disabling whatever reports.

**Fix:** In scope — prove the failure path and name the inspected count in the PR.

---

## AP-13 — The unhandled state falls into the destructive branch

```php
// write type still null on a SELECT builder, reachable through the ORM __call proxy
$sql = $this->type === 'update' ? $this->getUpdateSql() : $this->getDeleteSql();
```

**Found in review:** With no WHERE set, that fall-through is every row of the current FROM table.

**Rule:** The unhandled state throws. A default, `else` or fall-through branch is never the destructive action and never the success report — a stubbed or disabled command reports failure, and `match` beats a ternary wherever a third state exists.

**Fix:** In scope — enumerate the states and throw on the rest.

---

## AP-14 — Cache key omits an input that varies the value

```php
// eager-load constraints dropped from the key to stop serialize() complaining
$key = $sql . serialize($params);
```

**Found in review:** Queries sharing SQL and params but differing in their relation constraints then collided, and the cache served the wrong related data.

**Rule:** The key carries every input that changes the result — options, constraints, bound params. An input that will not serialise is not dropped: require an explicit discriminator from the caller, or do not cache.

**Fix:** In scope — key on SQL, params and relation names, plus a caller-supplied discriminator.

---

## AP-15 — Environment assumed from the authoring machine

```php
// DBAL 3 renamed the class; the old name resolves to nothing, so this is always false
if ($platform instanceof MySqlPlatform) { ... }
```

**Found in review:** Every MySQL-specific path was dead code on every MySQL install, and a baseline entry covered the missing class.

**Rule:** Verify a class name, a package directory and a path against the installed tree. Resolve paths from `__DIR__`, never absolute; assume a case-sensitive filesystem. A name that does not resolve makes `instanceof` silently constant.

**Fix:** In scope — resolve the real name and delete the baseline entry that hid it.

---

## AP-16 — Truthiness check or lost normalisation changes the contract

```php
// empty-but-loaded collection: the key vanishes from the JSON instead of reporting 0
if ($post->comments) {
    $data['comments_pending'] = $pending;
}
```

**Found in review:** An empty collection is not an absent one, and a dropped `trim()` orphaned the very config entry the rename was meant to move.

**Rule:** When logic moves into a presenter, repository or validator, diff the observable output against what it replaced: every key, every empty/zero/null case, every normalisation step. A truthiness check is not a null check.

**Fix:** In scope — restore the contract and pin the empty case with a test.
