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

# Whether a proxy list names one. The application splits the value on commas and
# trims the parts, so a value of nothing but separators and whitespace leaves it
# trusting no proxy, and this has to read such a value the same way.
names_a_proxy() {
    [ -n "$(printf '%s' "${1:-}" | tr -d '[:space:],')" ]
}

# Installs Pagekit into the configured database. A flag is passed only for a
# variable that is set, so the defaults stay in the setup command instead of being
# repeated here. Every connection parameter gets a flag of its own, the port and
# the SQLite file included: setup writes what it is given into config.php, and an
# installation that knew its database from the environment alone would lose it
# the day a deployment stopped setting the variable.
install_pagekit() {
    set -- setup --no-interaction --password "$PAGEKIT_ADMIN_PASSWORD"

    if [ -n "${PAGEKIT_DB_DRIVER:-}" ]; then
        set -- "$@" --db-driver "$PAGEKIT_DB_DRIVER"
    fi

    if [ -n "${PAGEKIT_DB_HOST:-}" ]; then
        set -- "$@" --db-host "$PAGEKIT_DB_HOST"
    fi

    if [ -n "${PAGEKIT_DB_PORT:-}" ]; then
        set -- "$@" --db-port "$PAGEKIT_DB_PORT"
    fi

    if [ -n "${PAGEKIT_DB_PATH:-}" ]; then
        set -- "$@" --db-path "$PAGEKIT_DB_PATH"
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
mkdir -p "$data_dir" storage tmp/cache tmp/logs tmp/packages tmp/sessions tmp/system tmp/temp

# The snapshots of removed packages are the one thing the application writes
# under tmp/ that is not a cache: a snapshot holds the only copy of a package
# the installation no longer has on disk, and restoring one is what the panel
# offers instead of a removal nobody can take back. tmp/ lives and dies with the
# container - a newer image, a down and an up - so the store goes on the data
# volume beside config.php, and tmp/snapshots is the link the application
# reaches it through.
#
# Made here rather than left to the first snapshot: through a link that points
# nowhere, the application would create the name it holds instead of what it
# points at, and land back in the container's own tmp/.
snapshots_dir=$data_dir/snapshots
snapshots_link=$app_dir/tmp/snapshots

# Anything else at that name is somewhere snapshots are already being kept, or
# would be. Replacing it with the link would hide whatever is in it, and writing
# through it would fill a directory the container takes with it, so this is
# somebody's decision to look at rather than one to make here.
if [ -e "$snapshots_link" ] && [ ! -L "$snapshots_link" ]; then
    echo "entrypoint: $snapshots_link is not the link into $snapshots_dir that the image makes it" >&2
    echo "entrypoint: snapshots kept anywhere else under tmp/ are lost with the container" >&2
    exit 1
fi

# The start ends here rather than carrying on, because carrying on is the
# failure: the application keeps its snapshots wherever this link leads, takes
# one before every removal and reports each removal as undoable. Led into the
# container's own tmp/, it would go on saying so and lose the lot with the
# container.
if ! mkdir -p "$snapshots_dir" || ! ln -sfn "$snapshots_dir" "$snapshots_link"; then
    echo "entrypoint: cannot keep the package snapshots in $snapshots_dir" >&2
    echo "entrypoint: a snapshot is the only copy of a removed package, so it may not live in a directory the container takes with it" >&2
    echo "entrypoint: mount the data volume at $data_dir and let the account the container serves as (uid 33) write it" >&2
    exit 1
fi

# config.php is written by the installer and rewritten whenever an administrator
# saves settings, so it belongs on the volume rather than in the image, where a
# link stands in for it. The image already ships that link for the default data
# directory - relinking is for one that moved, and needs an application root this
# container deliberately cannot write to. A real file in its place is someone's
# decision to keep the configuration in the image and is left untouched.
config_target=$data_dir/config.php

# Writing the link is what can fail, for the one reason named above. It says so
# and where the choice was made, rather than leaving the bare "Permission denied"
# of a link nobody asked for.
link_config() {
    if ln -sfn "$config_target" "$config_link" 2>/dev/null; then
        return
    fi

    echo "entrypoint: cannot point $config_link at $config_target" >&2
    echo "entrypoint: the application root is read-only here, so config.php stays the link the image was built with" >&2
    echo "entrypoint: leave PAGEKIT_DATA_DIR at the path the image uses and mount the data volume there instead" >&2
    exit 1
}

if [ -L "$config_link" ]; then
    if [ "$(readlink "$config_link")" != "$config_target" ]; then
        link_config
    fi
elif [ ! -e "$config_link" ]; then
    link_config
fi

# The link dangles until an installation writes the file, which is therefore what
# "installed" means here.
if enabled "${PAGEKIT_AUTO_SETUP:-0}" && [ ! -f "$config_link" ]; then
    if [ -z "${PAGEKIT_ADMIN_PASSWORD:-}" ]; then
        echo "entrypoint: PAGEKIT_AUTO_SETUP needs PAGEKIT_ADMIN_PASSWORD" >&2
        exit 1
    fi

    echo "entrypoint: no installation found, running setup"

    # set -e ends the start on a failed setup: a container that came up anyway
    # would put the web installer on whatever address it is reachable at.
    install_pagekit

    echo "entrypoint: setup complete"
fi

# Schema updates belong to a start, never to an image build: the image is built
# once and started against as many databases as it is deployed to. A migration
# that fails ends the start with it, rather than serving the new code against the
# schema it did not get. Every replica that starts runs this, so a deployment
# that scales out migrates the same database from several containers at once -
# keep it to one, or migrate as a job of its own before the rest come up.
if enabled "${PAGEKIT_AUTO_MIGRATE:-0}" && [ -f "$config_link" ]; then
    echo "entrypoint: migrating"
    php pagekit migration:migrate --no-interaction
fi

# The redirect to HTTPS in public/.htaccess believes X-Forwarded-Proto only on a
# server started with this define, and the deployment naming its proxies is what
# says a proxy is in front. Deciding it here rather than in the image is what
# keeps the header from being a way around the redirect for everyone else - and
# why a list that names nobody defines nothing: PHP trusts no proxy for such a
# value, and Apache taking the header on it would be that way around. Only the
# server takes the flag; the image is run with a command of its own often enough
# - a migration, a shell - and those would refuse it.
if names_a_proxy "${PAGEKIT_TRUSTED_PROXIES:-}"; then
    case "${1:-}" in
        *apache2*) set -- "$@" -D PAGEKIT_TRUSTED_PROXY ;;
    esac
fi

exec "$@"
