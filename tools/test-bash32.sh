#!/usr/bin/env bash
set -euo pipefail

project_dir=$(git rev-parse --show-toplevel)
docker run --rm --network none \
    --mount "type=bind,src=$project_dir,dst=/repo,readonly" \
    bash:3.2 /repo/tests/installer-shell.sh
