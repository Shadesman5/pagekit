#!/usr/bin/env node

/**
 * Builds the webroot once and then keeps the published files, the JS bundles
 * and the stylesheets up to date on change.
 *
 * The copied assets are not watched: their sources only change with an
 * install. The cheap watchers come up first; bringing up one Vite watcher per
 * bundle entry takes a moment.
 *
 * Usage: node scripts/watch.mjs
 */

import process from 'node:process';

import { copyAssets } from './assets.mjs';
import { watchBundles } from './bundles.mjs';
import { linkStorage, watchStatics } from './publish.mjs';
import { watchStyles } from './styles.mjs';

try {
  linkStorage();
  watchStatics();
  copyAssets();
  await watchStyles();
  await watchBundles();
} catch (error) {
  console.error(error.message);
  // The watchers that did come up would otherwise keep a half-started session
  // alive instead of letting the failure end it.
  process.exit(1);
}
