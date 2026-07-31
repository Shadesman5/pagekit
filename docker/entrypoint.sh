#!/bin/sh
# Startup of the Pagekit production container: prepares the paths the application
# writes to, keeps config.php on the data volume, and optionally installs or
# migrates before handing the process over to the webserver.
#
# Everything here runs on every start and has to survive being run again - a
# container is restarted far more often than it is created.
set -eu

app_dir=/var/www/html
data_dir=${PAGEKIT_DATA_DIR:-/var/www/data}
config_link=$app_dir/config.php

# Whether a PAGEKIT_AUTO_* switch is on, accepting the spellings the application
# accepts for its own booleans.
enabled() {
    case "${1:-}" in
        1 | [Tt][Rr][Uu][Ee] | [Oo][Nn] | [Yy][Ee][Ss]) return 0 ;;
        *) return 1 ;;
    esac
}

# Installs Pagekit into the configured database. A flag is passed only for a
# variable that is set, so the defaults stay in the setup command instead of being
# repeated here. The SQLite file has no flag of its own: PAGEKIT_DB_PATH reaches
# the installer through the configuration chain, which the console shares with
# the web request.
install_pagekit() {
    set -- setup --no-interaction --password "$PAGEKIT_ADMIN_PASSWORD"

    if [ -n "${PAGEKIT_DB_DRIVER:-}" ]; then
        set -- "$@" --db-driver "$PAGEKIT_DB_DRIVER"
    fi

    if [ -n "${PAGEKIT_DB_HOST:-}" ]; then
        set -- "$@" --db-host "$PAGEKIT_DB_HOST"
    fi

    if [ -n "${PAGEKIT_DB_NAME:-}" ]; then
        set -- "$@" --db-name "$PAGEKIT_DB_NAME"
    fi

    if [ -n "${PAGEKIT_DB_USER:-}" ]; then
        set -- "$@" --db-user "$PAGEKIT_DB_USER"
    fi

    if [ -n "${PAGEKIT_DB_PASSWORD:-}" ]; then
        set -- "$@" --db-pass "$PAGEKIT_DB_PASSWORD"
    fi

    if [ -n "${PAGEKIT_DB_PREFIX:-}" ]; then
        set -- "$@" --db-prefix "$PAGEKIT_DB_PREFIX"
    fi

    if [ -n "${PAGEKIT_ADMIN_USERNAME:-}" ]; then
        set -- "$@" --username "$PAGEKIT_ADMIN_USERNAME"
    fi

    if [ -n "${PAGEKIT_ADMIN_MAIL:-}" ]; then
        set -- "$@" --mail "$PAGEKIT_ADMIN_MAIL"
    fi

    if [ -n "${PAGEKIT_SITE_TITLE:-}" ]; then
        set -- "$@" --title "$PAGEKIT_SITE_TITLE"
    fi

    if [ -n "${PAGEKIT_LOCALE:-}" ]; then
        set -- "$@" --locale "$PAGEKIT_LOCALE"
    fi

    php pagekit "$@"
}

cd "$app_dir"

# A volume can be mounted empty and tmp/ is container-local, so the directories
# the application writes to are recreated on every start.
mkdir -p "$data_dir" storage tmp/cache tmp/logs tmp/packages tmp/sessions tmp/temp

# config.php is written by the installer and rewritten whenever an administrator
# saves settings, so it belongs on the volume rather than in the image, where a
# link stands in for it. The image already ships that link for the default data
# directory - relinking is for one that moved, and needs an application root this
# container deliberately cannot write to. A real file in its place is someone's
# decision to keep the configuration in the image and is left untouched.
config_target=$data_dir/config.php

if [ -L "$config_link" ]; then
    if [ "$(readlink "$config_link")" != "$config_target" ]; then
        ln -sfn "$config_target" "$config_link"
    fi
elif [ ! -e "$config_link" ]; then
    ln -sfn "$config_target" "$config_link"
fi

# The link dangles until an installation writes the file, which is therefore what
# "installed" means here.
if enabled "${PAGEKIT_AUTO_SETUP:-0}" && [ ! -f "$config_link" ]; then
    if [ -z "${PAGEKIT_ADMIN_PASSWORD:-}" ]; then
        echo "entrypoint: PAGEKIT_AUTO_SETUP needs PAGEKIT_ADMIN_PASSWORD" >&2
        exit 1
    fi

    echo "entrypoint: no installation found, running setup"

    # The command reports through the file it writes rather than its exit status,
    # which is unreliable: the console never propagates the command's code.
    install_pagekit || true

    if [ ! -f "$config_link" ]; then
        echo "entrypoint: setup did not write $config_link" >&2
        exit 1
    fi

    echo "entrypoint: setup complete"
fi

# Schema updates belong to a start, never to an image build: the image is built
# once and started against as many databases as it is deployed to.
if enabled "${PAGEKIT_AUTO_MIGRATE:-0}" && [ -f "$config_link" ]; then
    echo "entrypoint: migrating"
    php pagekit migration:migrate --no-interaction
fi

exec "$@"
