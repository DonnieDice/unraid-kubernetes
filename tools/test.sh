#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
cd "$root"

while IFS= read -r file; do bash -n "$file"; done < <(find src tools -type f \( -name '*.sh' -o -path '*/event/started' -o -path '*/event/stopping_docker' -o -name 'rc.*' \))
while IFS= read -r file; do php -l "$file"; done < <(find src -type f -name '*.php')
# shellcheck disable=SC2016
UNRAID_K8S_SETTINGS="$root/tests/fixtures/tower.settings.cfg" php -r '
require "src/usr/local/emhttp/plugins/unraid.kubernetes/include/config.php";
$status = dm_k8s_status();
exit(
    isset($status["cluster"]["state"], $status["nodes"], $status["pods"])
    && dm_k8s_memory("1048576Ki") === "1.0 GiB"
    ? 0
    : 1
);
'
php tests/settings-roundtrip.php
node --check src/usr/local/emhttp/plugins/unraid.kubernetes/scripts/kubernetes.js
xmllint --noout plugin/unraid.kubernetes.plg.in
xmllint --noout plugin/unraid.kubernetes.plg ca_profile.xml plugins/*.xml

if command -v shellcheck >/dev/null 2>&1; then
  find src tools -type f \( -name '*.sh' -o -path '*/event/started' -o -path '*/event/stopping_docker' -o -name 'rc.*' \) -print0 | xargs -0 shellcheck
fi

if grep -RIE 'docker rm|kubectl delete|kubectl apply|cat[[:space:]].*token' src; then
  echo "Unsafe mutation or credential-output pattern found" >&2
  exit 1
fi

echo "Validation passed"
