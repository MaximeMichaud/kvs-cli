#!/usr/bin/env bash

# Install a native PHAR or the Docker launcher with the same command.
set -euo pipefail

fail() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

install_dir=${KVS_INSTALL_DIR:-/usr/local/bin}
runtime=auto
while (($#)); do
    case $1 in
        --install-dir)
            (($# >= 2)) && [[ -n $2 ]] || fail '--install-dir requires a directory.'
            install_dir=$2
            shift 2
            ;;
        --docker|--native)
            runtime=${1#--}
            shift
            ;;
        --help|-h)
            printf 'Usage: bash install.sh [--install-dir DIR] [--docker|--native]\n'
            exit 0
            ;;
        *) fail "Unknown option: $1" ;;
    esac
done

command -v curl >/dev/null 2>&1 || fail 'curl is required.'
if command -v sha256sum >/dev/null 2>&1; then
    checksum_command=(sha256sum)
elif command -v shasum >/dev/null 2>&1; then
    checksum_command=(shasum -a 256)
else
    fail 'sha256sum or shasum is required to verify the download.'
fi

native_available=false
if command -v php >/dev/null 2>&1 && php -r '
    exit(PHP_VERSION_ID >= 80100 && extension_loaded("phar") &&
        extension_loaded("mbstring") && extension_loaded("json") &&
        extension_loaded("pdo_mysql") ? 0 : 1);
' >/dev/null 2>&1; then
    native_available=true
fi
if [[ $runtime == auto ]]; then
    if $native_available; then
        runtime=native
    else
        runtime=docker
    fi
fi
if [[ $runtime == native ]]; then
    $native_available || fail 'Native installation requires PHP 8.1+ with phar, mbstring, json, and pdo_mysql.'
else
    command -v docker >/dev/null 2>&1 ||
        fail 'No suitable local PHP runtime or Docker found. Use PHP 8.1+ or a running KVS Docker installation.'
    docker ps --format '{{.Names}}' >/dev/null || fail 'Cannot access the Docker daemon.'
fi

release_url=${KVS_RELEASE_URL:-https://github.com/MaximeMichaud/kvs-cli/releases/latest/download}
launcher_url=${KVS_LAUNCHER_URL:-https://raw.githubusercontent.com/MaximeMichaud/kvs-cli/main/bin/kvs-docker}
temporary_dir=$(mktemp -d)
destination_stage=
privilege=()
cleanup() {
    rm -rf -- "$temporary_dir"
    if [[ -n $destination_stage ]]; then
        "${privilege[@]}" rm -rf -- "$destination_stage"
    fi
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

printf 'Downloading KVS CLI (%s runtime)...\n' "$runtime"
curl -fsSL "$release_url/kvs.phar" -o "$temporary_dir/kvs.phar"
curl -fsSL "$release_url/kvs.phar.sha256" -o "$temporary_dir/kvs.phar.sha256"
read -r expected_checksum checksum_filename < "$temporary_dir/kvs.phar.sha256"
[[ $expected_checksum =~ ^[[:xdigit:]]{64}$ && $checksum_filename == kvs.phar ]] ||
    fail 'Invalid PHAR checksum file.'
actual_checksum=$("${checksum_command[@]}" "$temporary_dir/kvs.phar")
[[ ${actual_checksum%% *} == "$expected_checksum" ]] || fail 'PHAR checksum verification failed.'

if [[ $runtime == docker ]]; then
    # Existing releases contain only the PHAR; use the repository launcher then.
    if ! curl -fsSL "$release_url/kvs-docker" -o "$temporary_dir/kvs" 2>/dev/null; then
        curl -fsSL "$launcher_url" -o "$temporary_dir/kvs"
    fi
    bash -n "$temporary_dir/kvs"
else
    php "$temporary_dir/kvs.phar" --version
fi

# Stage on the destination filesystem so replacing the executable is atomic.
parent=$install_dir
while [[ ! -d $parent ]]; do
    parent=$(dirname -- "$parent")
done
if [[ ! -w $parent ]]; then
    command -v sudo >/dev/null 2>&1 || fail "Cannot write to $install_dir and sudo is unavailable. Use --install-dir."
    privilege=(sudo)
fi
"${privilege[@]}" mkdir -p -- "$install_dir"
destination_stage=$("${privilege[@]}" mktemp -d "$install_dir/.kvs-install.XXXXXXXXXX")
if [[ $runtime == docker ]]; then
    "${privilege[@]}" install -m 0644 "$temporary_dir/kvs.phar" "$destination_stage/kvs.phar"
    "${privilege[@]}" install -m 0755 "$temporary_dir/kvs" "$destination_stage/kvs"
    "${privilege[@]}" mv -f -- "$destination_stage/kvs.phar" "$install_dir/kvs.phar"
else
    "${privilege[@]}" install -m 0755 "$temporary_dir/kvs.phar" "$destination_stage/kvs"
fi
"${privilege[@]}" mv -f -- "$destination_stage/kvs" "$install_dir/kvs"
printf 'Installed KVS CLI (%s runtime) at %s/kvs.\n' "$runtime" "$install_dir"
printf 'Run: %s/kvs --version\n' "$install_dir"
