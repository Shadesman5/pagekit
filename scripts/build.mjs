#!/usr/bin/env node

/**
 * Builds the webroot: the storage link, the published files, the copied
 * assets, the JS bundles and the stylesheets. None of it is committed, so a
 * checkout serves nothing until this has run.
 *
 * The publication pass goes first. It mirrors the sources, so anything a
 * previous build left in them is overwritten by the builder that owns the
 * path.
 *
 * Usage: node scripts/build.mjs
 */

import process from 'node:process';

import { copyAssets } from './assets.mjs';
import { buildBundles } from './bundles.mjs';
import { linkStorage, publishStatics } from './publish.mjs';
import { buildStyles } from './styles.mjs';

try {
  linkStorage();
  publishStatics();
  copyAssets();
  await buildBundles();
  await buildStyles();
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
