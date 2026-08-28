#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
version=$(tr -d '[:space:]' <"$root/VERSION")
release_epoch=$(tr -d '[:space:]' <"$root/RELEASE_EPOCH")
build_root="$root/.build/package"
artifact="$root/dist/unraid.kubernetes-${version}-noarch-1.txz"

rm -rf "$root/.build"
mkdir -p "$build_root" "$root/dist"
cp -a "$root/src/." "$build_root/"
chmod 0755 \
  "$build_root/etc/rc.d/rc.unraid-kubernetes" \
  "$build_root/usr/local/emhttp/plugins/unraid.kubernetes/scripts/install.sh" \
  "$build_root/usr/local/emhttp/plugins/unraid.kubernetes/event/started" \
  "$build_root/usr/local/emhttp/plugins/unraid.kubernetes/event/stopping_docker" \
  "$build_root/install/doinst.sh"

[[ "$release_epoch" =~ ^[0-9]+$ ]] || { echo "Invalid RELEASE_EPOCH" >&2; exit 1; }
tar --sort=name --mtime="@${release_epoch}" --owner=0 --group=0 --numeric-owner \
  -C "$build_root" -cJf "$artifact" etc install usr
echo "$artifact"
