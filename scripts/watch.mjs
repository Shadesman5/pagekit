#!/usr/bin/env node

/**
 * Rebuilds the JS bundles and the stylesheets on change.
 *
 * The stylesheet watchers come up first because they are cheap; bringing up
 * one Vite watcher per bundle entry takes a moment.
 *
 * Usage: node scripts/watch.mjs
 */

import { watchBundles } from './bundles.mjs';
import { watchStyles } from './styles.mjs';

watchStyles();

await watchBundles();
