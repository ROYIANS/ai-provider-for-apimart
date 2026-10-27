#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
slug="ai-provider-for-apimart"
version="$(sed -n 's/^ \* Version: *//p' "$repo_root/plugin.php")"

if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][A-Za-z0-9.-]+)?$ ]]; then
    echo 'Could not read a valid plugin version from plugin.php.' >&2
    exit 1
fi

stage="$(mktemp -d "${TMPDIR:-/tmp}/apimart-plugin.XXXXXX")"
trap 'rm -rf "$stage"' EXIT

mkdir -p "$stage/$slug" "$repo_root/dist"
rsync -a --exclude-from="$repo_root/.distignore" "$repo_root/" "$stage/$slug/"

archive_name="$slug-$version.zip"
(
    cd "$stage"
    zip -X -q -r "$archive_name" "$slug"
    unzip -tq "$archive_name"
)
mv "$stage/$archive_name" "$repo_root/dist/$archive_name"

echo "Built $repo_root/dist/$archive_name"
shasum -a 256 "$repo_root/dist/$archive_name"
