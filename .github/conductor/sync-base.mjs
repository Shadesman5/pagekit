// Merge origin/<base> into a published feature branch. Merge, not rebase: Conductor,
// cloud agents, nested workers and the maintainer's clone all push to the same ref, so
// a rebase would rewrite SHAs that are already on origin and need a force-push.
//
// Always merge the remote-tracking ref (`origin/develop`), never the local branch of
// the same name. A Cloud Agent VM boots from a Cursor Build of the default branch; the
// local `develop` there is that snapshot and can lag origin even when origin is current.

import { execFileSync } from 'node:child_process';

const SAFE_REF = /^[A-Za-z0-9][A-Za-z0-9._/-]*$/;

const BOT_EMAIL = '41898282+github-actions[bot]@users.noreply.github.com';
const BOT_NAME = 'github-actions[bot]';

/** @returns {string} */
export function assertSafeRef(label, value) {
  const name = String(value ?? '');
  if (
    !SAFE_REF.test(name) ||
    name.includes('..') ||
    name.endsWith('.lock') ||
    name.startsWith('-')
  ) {
    throw new Error(`${label}: unsafe ref ${JSON.stringify(name)}`);
  }
  return name;
}

/**
 * @param {string} cwd
 * @param {string[]} args
 * @returns {string}
 */
export function gitAt(cwd, args) {
  return execFileSync('git', args, {
    cwd,
    stdio: ['ignore', 'pipe', 'pipe'],
    encoding: 'utf8',
    shell: false,
    env: { ...process.env, GIT_TERMINAL_PROMPT: '0' }
  }).trim();
}

function detailOf(err) {
  const stderr = err?.stderr != null ? String(err.stderr) : '';
  const stdout = err?.stdout != null ? String(err.stdout) : '';
  return (stderr || stdout || err?.message || '').trim();
}

function ensureIdentity(git) {
  try {
    if (git('config', '--get', 'user.email')) return;
  } catch {
    /* unset */
  }
  git('config', 'user.email', BOT_EMAIL);
  git('config', 'user.name', BOT_NAME);
}

/**
 * Fetch `origin/<base>` and merge it into HEAD (the feature branch), then push.
 *
 * @param {object} opts
 * @param {(…args: string[]) => string} [opts.git]
 * @param {(...msg: unknown[]) => void} [opts.log]
 * @param {string} opts.branch feature branch name (must already be checked out)
 * @param {string} opts.base   base branch name (e.g. develop)
 * @param {boolean} [opts.push=true]
 * @returns {{ status: 'up-to-date'|'merged'|'conflict', before: string, after: string, detail?: string }}
 */
export function syncFeatureWithBase({ git, log = () => {}, branch, base, push = true } = {}) {
  const feature = assertSafeRef('branch', branch);
  const baseRef = assertSafeRef('base', base);
  const run = git || ((...args) => gitAt(process.cwd(), args));

  run('fetch', 'origin', baseRef);

  const before = run('rev-parse', 'HEAD');
  ensureIdentity((...args) => run(...args));

  try {
    run('merge', `origin/${baseRef}`, '--no-edit');
  } catch (err) {
    try {
      run('merge', '--abort');
    } catch {
      /* merge never started, or abort is itself failing — tree must stay usable */
    }
    const detail = detailOf(err);
    log(`base sync: merge origin/${baseRef} into ${feature} conflicted — aborted`);
    return { status: 'conflict', before, after: run('rev-parse', 'HEAD'), detail };
  }

  const after = run('rev-parse', 'HEAD');
  if (before === after) {
    log(`base sync: ${feature} already contains origin/${baseRef}`);
    return { status: 'up-to-date', before, after };
  }

  if (push) {
    run('push', 'origin', feature);
  }

  log(
    `base sync: merged origin/${baseRef} into ${feature} (${before.slice(0, 7)}..${after.slice(0, 7)})`
  );
  return { status: 'merged', before, after };
}
