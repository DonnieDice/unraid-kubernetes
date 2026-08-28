#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
version=$(tr -d '[:space:]' <"$root/VERSION")
package="$root/dist/unraid.kubernetes-${version}-noarch-1.txz"
output="$root/dist/unraid.kubernetes.plg"

[[ -s "$package" ]] || bash "$root/tools/build.sh" >/dev/null
checksum=$(sha256sum "$package" | awk '{print $1}')
sed -e "s/@VERSION@/${version}/g" -e "s/@PACKAGE_SHA256@/${checksum}/g" \
  "$root/plugin/unraid.kubernetes.plg.in" >"$output"
xmllint --noout "$output"
echo "$output"
