#!/usr/bin/env bash

# Exercise installer persistence and launcher dispatch with an older Bash too.
set -euo pipefail
project_dir=$(cd -- "$(dirname -- "$0")/.." && pwd -P)
working_dir=$(mktemp -d)
trap 'rm -rf -- "$working_dir"' EXIT
mkdir -p "$working_dir/bin" "$working_dir/payload" "$working_dir/site/admin/include" "$working_dir/runtime"
export TMPDIR="$working_dir/runtime"
export FIXTURE_COPY_LOG="$working_dir/copies"
printf '<?php\n' > "$working_dir/site/admin/include/setup.php"
printf 'synthetic payload\n' > "$working_dir/payload/kvs.phar"
cp "$project_dir/bin/kvs-docker" "$working_dir/payload/kvs-docker"
(cd "$working_dir/payload" && sha256sum kvs.phar > kvs.phar.sha256)
export FIXTURE_DOWNLOAD="$working_dir/payload"
export FIXTURE_SITE="$working_dir/site"
export KVS_PATH=
export KVS_DOCKER_CONTAINER=
unset KVS_CLI_PHAR

cat > "$working_dir/bin/php" <<'SH'
#!/bin/sh
if [ "$1" = -r ]; then exit 0; fi
printf 'Fixture CLI version\n'
SH
cat > "$working_dir/bin/curl" <<'SH'
#!/bin/sh
if [ "${FIXTURE_DOWNLOAD_FAILURE:-0}" = 1 ]; then exit 22; fi
url=
destination=
while [ "$#" -gt 0 ]; do
    case $1 in
        -o) destination=$2; shift 2 ;;
        https://*) url=$1; shift ;;
        *) shift ;;
    esac
done
cp "$FIXTURE_DOWNLOAD/${url##*/}" "$destination"
SH
cat > "$working_dir/bin/docker" <<'SH'
#!/bin/sh
case $1 in
    ps)
        if [ "${FIXTURE_NO_CONTAINERS:-0}" != 1 ]; then
            printf 'fixture-php\tphp-fpm\n'
        fi
        ;;
    inspect)
        if [ "$3" = '{{.State.Running}}' ]; then printf 'true\n';
        else printf '%s\t/srv/site\n' "$FIXTURE_SITE"; fi
        ;;
    cp)
        printf 'copy\n' >> "$FIXTURE_COPY_LOG"
        shift
        while [ "$#" -gt 2 ]; do shift; done
        cp "$1" "${2#*:}"
        ;;
    exec)
        shift
        while [ "$#" -gt 0 ] && [ "$1" != fixture-php ]; do shift; done
        [ "$#" -gt 0 ] || exit 99
        shift
        case $1 in
            sh)
                case $3 in
                    *admin/include/setup.php*) printf '/srv/site\n' ;;
                    *) exec "$@" ;;
                esac
                ;;
            rm) exec "$@" ;;
            php)
                if [ "$6" = false ]; then cp "$7" "$4"; fi
                printf 'Fixture CLI output\n'
                ;;
            *) exit 99 ;;
        esac
        ;;
    *) exit 99 ;;
esac
SH
chmod +x "$working_dir/bin/php" "$working_dir/bin/curl" "$working_dir/bin/docker"
export PATH="$working_dir/bin:$PATH"

bash "$project_dir/install.sh" --native --install-dir "$working_dir/native"
test -x "$working_dir/native/kvs"
cmp "$working_dir/payload/kvs.phar" "$working_dir/native/kvs"

if FIXTURE_DOWNLOAD_FAILURE=1 bash "$project_dir/install.sh" --native --install-dir "$working_dir/native"; then
    printf 'Failed download incorrectly reported success.\n' >&2
    exit 1
else
    result=$?
    test "$result" = 22
fi
cmp "$working_dir/payload/kvs.phar" "$working_dir/native/kvs"

bash "$project_dir/install.sh" --docker --install-dir "$working_dir/docker"
test -x "$working_dir/docker/kvs"
cmp "$project_dir/bin/kvs-docker" "$working_dir/docker/kvs"
cmp "$working_dir/payload/kvs.phar" "$working_dir/docker/kvs.phar"
test "$(bash "$working_dir/docker/kvs")" = 'Fixture CLI output'
test "$(bash "$working_dir/docker/kvs" --version)" = 'Fixture CLI output'
test "$(wc -l < "$FIXTURE_COPY_LOG")" -eq 1
if FIXTURE_NO_CONTAINERS=1 bash "$working_dir/docker/kvs" --version; then
    printf 'Missing container incorrectly reported success.\n' >&2
    exit 1
fi
printf 'Installer and launcher checks passed with Bash %s.\n' "$BASH_VERSION"
