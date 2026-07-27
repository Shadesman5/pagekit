#!/usr/bin/env node

/**
 * Builds everything once and then rebuilds the JS bundles and the stylesheets
 * on change.
 *
 * The runtime assets are copied but not watched: their sources only change
 * with an install, and the LESS roots import uikit from `app/assets/uikit/`,
 * so the copy has to be in place before anything else runs.
 *
 * The stylesheets come up first because they are cheap; bringing up one Vite
 * watcher per bundle entry takes a moment.
 *
 * Usage: node scripts/watch.mjs
 */

import process from 'node:process';

import { copyAssets } from './assets.mjs';
import { watchBundles } from './bundles.mjs';
import { watchStyles } from './styles.mjs';

try {
  copyAssets();
  await watchStyles();
  await watchBundles();
} catch (error) {
  console.error(error.message);
  // The watchers that did come up would otherwise keep a half-started session
  // alive instead of letting the failure end it.
  process.exit(1);
}
