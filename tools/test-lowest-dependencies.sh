#!/usr/bin/env bash
set -euo pipefail

project_dir=$(git rev-parse --show-toplevel)
runtime_temp=${TMPDIR:-$(php -r 'echo sys_get_temp_dir();')}
workspace=$(mktemp -d "$runtime_temp/kvs-cli-lowest.XXXXXXXX")
container="kvs-cli-lowest-${workspace##*.}"
cleanup() {
    docker container rm --force "$container" >/dev/null 2>&1 || true
    rm -rf -- "$workspace"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Copy tracked files from the snapshot exposed by the outer pre-commit hook.
# A separate vendor tree keeps dependency resolution out of the working tree.
git -C "$project_dir" ls-files -z | tar -C "$project_dir" --null -T - -cf - | tar -C "$workspace" -xf -
image_hash=$(git hash-object "$project_dir/tools/php81.Dockerfile")
test_uid=$(id -u)
test_gid=$(id -g)
image="kvs-cli-test-php81:$image_hash-$test_uid-$test_gid"
docker build --quiet --file "$project_dir/tools/php81.Dockerfile" \
    --build-arg "KVS_CLI_TEST_UID=$test_uid" --build-arg "KVS_CLI_TEST_GID=$test_gid" \
    --tag "$image" "$project_dir/tools"
cache_dir=$(composer config cache-dir --absolute)
mkdir -p "$cache_dir" "$workspace/runtime-temp"
docker run --rm --name "$container" --user "$test_uid:$test_gid" \
    --mount "type=bind,src=$workspace,dst=/work" \
    --mount "type=bind,src=$cache_dir,dst=/composer-cache" \
    --mount "type=bind,src=$workspace/runtime-temp,dst=$runtime_temp" \
    --env COMPOSER_HOME=/work/.composer --env COMPOSER_CACHE_DIR=/composer-cache \
    --env TMPDIR="$runtime_temp" --env XDEBUG_MODE=off "$image" sh -ec '
        php --version
        find . -type f -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null
        php -l bin/kvs >/dev/null
        composer update --prefer-lowest --prefer-stable --no-scripts --no-progress --no-interaction
        php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
        php vendor/bin/phpunit --configuration phpunit.xml.dist --no-progress \
            --fail-on-warning --fail-on-risky --fail-on-deprecation
    '
