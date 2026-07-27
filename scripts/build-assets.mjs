#!/usr/bin/env node

/**
 * Fills the webroot with everything that is copied rather than compiled: the
 * storage link, the files committed ready to serve and the runtime assets from
 * `node_modules`.
 *
 * Usage: node scripts/build-assets.mjs
 */

import process from 'node:process';

import { copyAssets } from './assets.mjs';
import { linkStorage, publishStatics } from './publish.mjs';

try {
  linkStorage();
  publishStatics();
  copyAssets();
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
