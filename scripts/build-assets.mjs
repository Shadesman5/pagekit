#!/usr/bin/env node

/**
 * Copies the runtime assets out of `node_modules`.
 *
 * Usage: node scripts/build-assets.mjs
 */

import process from 'node:process';

import { copyAssets } from './assets.mjs';

try {
  copyAssets();
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
