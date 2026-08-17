import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { execFileSync } from 'node:child_process';
import { assertSafeRef, gitAt, syncFeatureWithBase } from './sync-base.mjs';

function git(cwd, ...args) {
  return execFileSync('git', args, {
    cwd,
    stdio: ['ignore', 'pipe', 'pipe'],
    encoding: 'utf8',
    shell: false
  }).trim();
}

function write(cwd, name, body) {
  writeFileSync(join(cwd, name), body);
}

/** Bare origin + clone on `develop`, identity set, gpgsign off. */
function fixture() {
  const root = mkdtempSync(join(tmpdir(), 'pk-sync-base-'));
  const origin = join(root, 'origin.git');
  const repo = join(root, 'repo');
  git(root, 'init', '--bare', '--initial-branch=develop', origin);
  git(root, 'clone', origin, repo);
  git(repo, 'config', 'user.email', 'conductor@example.test');
  git(repo, 'config', 'user.name', 'conductor-test');
  git(repo, 'config', 'commit.gpgsign', 'false');
  write(repo, 'README', 'base\n');
  git(repo, 'add', 'README');
  git(repo, 'commit', '-m', 'initial');
  git(repo, 'branch', '-M', 'develop');
  git(repo, 'push', '-u', 'origin', 'develop');
  return { root, origin, repo };
}

function runSync(repo, { push = true } = {}) {
  const logs = [];
  const result = syncFeatureWithBase({
    git: (...args) => gitAt(repo, args),
    log: (...msg) => logs.push(msg.join(' ')),
    branch: 'feature/ticket',
    base: 'develop',
    push
  });
  return { result, logs };
}

test('assertSafeRef rejects traversal and option-shaped names', () => {
  assert.equal(assertSafeRef('base', 'develop'), 'develop');
  assert.throws(() => assertSafeRef('base', 'foo;rm'), /unsafe/);
  assert.throws(() => assertSafeRef('base', '-u'), /unsafe/);
  assert.throws(() => assertSafeRef('base', 'a..b'), /unsafe/);
  assert.throws(() => assertSafeRef('base', 'x.lock'), /unsafe/);
});

test('already up to date when the feature branch is the base tip', () => {
  const { root, repo } = fixture();
  try {
    git(repo, 'checkout', '-b', 'feature/ticket');
    git(repo, 'push', '-u', 'origin', 'feature/ticket');
    const { result } = runSync(repo);
    assert.equal(result.status, 'up-to-date');
    assert.equal(result.before, result.after);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test('fast-forwards a feature branch that has no unique commits', () => {
  const { root, repo } = fixture();
  try {
    const fork = git(repo, 'rev-parse', 'HEAD');
    git(repo, 'checkout', '-b', 'feature/ticket', fork);
    git(repo, 'push', '-u', 'origin', 'feature/ticket');

    git(repo, 'checkout', 'develop');
    write(repo, 'README', 'base\nlater\n');
    git(repo, 'add', 'README');
    git(repo, 'commit', '-m', 'develop moved');
    git(repo, 'push', 'origin', 'develop');
    const developTip = git(repo, 'rev-parse', 'HEAD');

    git(repo, 'checkout', 'feature/ticket');
    const { result } = runSync(repo);
    assert.equal(result.status, 'merged');
    assert.equal(git(repo, 'rev-parse', 'HEAD'), developTip);
    assert.equal(git(repo, 'rev-parse', 'origin/feature/ticket'), developTip);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test('merges origin/base, not a stale local branch of the same name', () => {
  const { root, repo } = fixture();
  try {
    const fork = git(repo, 'rev-parse', 'HEAD');
    git(repo, 'checkout', '-b', 'feature/ticket');
    write(repo, 'ticket.md', 'plan\n');
    git(repo, 'add', 'ticket.md');
    git(repo, 'commit', '-m', 'feature work');
    git(repo, 'push', '-u', 'origin', 'feature/ticket');

    git(repo, 'checkout', 'develop');
    write(repo, 'README', 'base\nfrom origin\n');
    git(repo, 'add', 'README');
    git(repo, 'commit', '-m', 'develop moved');
    git(repo, 'push', 'origin', 'develop');
    const originDevelop = git(repo, 'rev-parse', 'origin/develop');

    // Cloud Agent Build shape: local develop left at the fork, origin/develop ahead.
    git(repo, 'reset', '--hard', fork);
    git(repo, 'checkout', 'feature/ticket');

    const { result } = runSync(repo);
    assert.equal(result.status, 'merged');
    assert.equal(git(repo, 'merge-base', '--is-ancestor', originDevelop, 'HEAD'), '');
    const parents = git(repo, 'rev-parse', 'HEAD^@').split(/\r?\n/).filter(Boolean);
    assert.equal(parents.length, 2);
    assert.match(git(repo, 'show', 'HEAD:README'), /from origin/);
    assert.match(git(repo, 'show', 'HEAD:ticket.md'), /plan/);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test('aborts a conflict and leaves the feature branch usable', () => {
  const { root, repo } = fixture();
  try {
    git(repo, 'checkout', '-b', 'feature/ticket');
    write(repo, 'README', 'feature side\n');
    git(repo, 'add', 'README');
    git(repo, 'commit', '-m', 'feature edit');
    const featureTip = git(repo, 'rev-parse', 'HEAD');
    git(repo, 'push', '-u', 'origin', 'feature/ticket');

    git(repo, 'checkout', 'develop');
    write(repo, 'README', 'develop side\n');
    git(repo, 'add', 'README');
    git(repo, 'commit', '-m', 'develop edit');
    git(repo, 'push', 'origin', 'develop');

    git(repo, 'checkout', 'feature/ticket');
    const { result } = runSync(repo);
    assert.equal(result.status, 'conflict');
    assert.equal(git(repo, 'rev-parse', 'HEAD'), featureTip);
    assert.equal(git(repo, 'status', '--porcelain'), '');
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});
