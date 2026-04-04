#!/bin/bash
set -e

# GitHub Token mapping
if [[ -n "${PAGEKIT_BACKGROUND_AGENT:-}" ]]; then
    export GH_TOKEN="$PAGEKIT_BACKGROUND_AGENT"
fi

# PHP dependencies (update statt install, weil lock gitignored ist)
composer update --no-interaction --working-dir=.

# Node dependencies (yarn install triggert auch den Build via postinstall)
yarn install

# Writable directories
mkdir -p tmp/logs tmp/cache tmp/temp tmp/packages storage