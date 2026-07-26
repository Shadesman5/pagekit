#!/usr/bin/env node

/**
 * Builds the JS bundles, or keeps them up to date with `--watch`.
 *
 * Usage: node scripts/build-js.mjs [--watch] [--concurrency=N]
 */

import process from 'node:process';

import { buildBundles, watchBundles } from './bundles.mjs';

/**
 * @param {string[]} argv
 * @returns {{ watch: boolean, concurrency: number|undefined }} an omitted
 *   concurrency leaves the default to the builder
 */
function parseArgs(argv) {
    const options = { watch: false, concurrency: undefined };

    for (const arg of argv) {
        const concurrency = /^--concurrency=(\d+)$/.exec(arg);

        if (arg === '--watch' || arg === '-w') {
            options.watch = true;
        } else if (concurrency && Number(concurrency[1]) > 0) {
            options.concurrency = Number(concurrency[1]);
        } else {
            throw new Error(`Unknown argument: ${arg}`);
        }
    }

    return options;
}

try {
    const { watch, concurrency } = parseArgs(process.argv.slice(2));

    await (watch ? watchBundles() : buildBundles(concurrency));
} catch (error) {
    console.error(error.message);
    process.exitCode = 1;
}
