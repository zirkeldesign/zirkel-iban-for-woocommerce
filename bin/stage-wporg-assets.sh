#!/usr/bin/env bash
# Copies the plugin directory art that WordPress.org actually serves into the
# given directory. Both deploy workflows use it, so the release deploy and the
# assets-only deploy cannot drift apart: banner.svg and the README in
# .wordpress-org/assets stay in the repo as sources and never reach SVN.
#
# Usage: bin/stage-wporg-assets.sh <target-dir>
set -euo pipefail

target="${1:?usage: $0 <target-dir>}"
source_dir="$(cd "$(dirname "$0")/.." && pwd)/.wordpress-org/assets"

files=(
    icon.svg
    icon-256x256.png
    icon-128x128.png
    banner-772x250.png
    banner-1544x500.png
)

mkdir -p "$target"

for file in "${files[@]}"; do
    cp "$source_dir/$file" "$target/"
done

# Printed so the caller can prune whatever is not on the list.
printf '%s\n' "${files[@]}"
