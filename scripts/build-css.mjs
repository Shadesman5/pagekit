#!/usr/bin/env node

/**
 * Compiles the LESS stylesheets, or keeps them up to date with `--watch`.
 *
 * Usage: node scripts/build-css.mjs [--watch]
 */

import process from 'node:process';

import { buildStyles, watchStyles } from './styles.mjs';

if (process.argv.includes('--watch')) {
  watchStyles();
} else {
  try {
    await buildStyles();
  } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}
