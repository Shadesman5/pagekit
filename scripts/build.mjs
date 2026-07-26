#!/usr/bin/env node

/**
 * Builds everything the runtime serves: the copied assets, the JS bundles and
 * the stylesheets. None of it is committed, so a checkout is only servable
 * once this has run.
 *
 * The asset copy goes first: the LESS roots import uikit's sources from
 * `app/assets/uikit/`.
 *
 * Usage: node scripts/build.mjs
 */

import process from 'node:process';

import { copyAssets } from './assets.mjs';
import { buildBundles } from './bundles.mjs';
import { buildStyles } from './styles.mjs';

try {
  copyAssets();
  await buildBundles();
  await buildStyles();
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
