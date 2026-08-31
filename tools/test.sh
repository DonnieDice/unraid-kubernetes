#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
cd "$root"

while IFS= read -r file; do bash -n "$file"; done < <(find src tools -type f \( -name '*.sh' -o -path '*/event/started' -o -path '*/event/stopping_docker' -o -name 'rc.*' \))
while IFS= read -r file; do php -l "$file"; done < <(find src -type f -name '*.php')
php -l src/usr/local/emhttp/plugins/unraid.kubernetes/Kubernetes.Dashboard.page
# shellcheck disable=SC2016
UNRAID_K8S_SETTINGS="$root/tests/fixtures/tower.settings.cfg" php -r '
require "src/usr/local/emhttp/plugins/unraid.kubernetes/include/config.php";
$status = dm_k8s_status();
exit(
    isset($status["cluster"]["state"], $status["nodes"], $status["pods"])
    && dm_k8s_memory("1048576Ki") === "1.0 GiB"
    && dm_k8s_cpu_millicores("125000000n") === 125
    && dm_k8s_memory_bytes("1024Mi") === 1073741824
    && dm_k8s_format_cpu(1250) === "1.25 cores"
    && dm_k8s_format_cpu(125, "percent") === "12.5% core"
    && dm_k8s_format_cpu(125, "cores") === "0.13 cores"
    ? 0
    : 1
);
'
php tests/settings-roundtrip.php
node --check src/usr/local/emhttp/plugins/unraid.kubernetes/scripts/kubernetes.js
xmllint --noout plugin/unraid.kubernetes.plg.in
xmllint --noout plugin/unraid.kubernetes.plg ca_profile.xml plugins/*.xml

docker_page=src/usr/local/emhttp/plugins/unraid.kubernetes/Kubernetes.Docker.page
grep -qx 'Menu="Docker"' "$docker_page"
if grep -q '^Title=' "$docker_page"; then
  echo "Docker integration must remain an untitled parent-page extension" >&2
  exit 1
fi
grep -q 'data-dm-k8s-docker-title' "$docker_page"
grep -q 'class="dm-k8s-advanced">CPU &amp; Memory load</th><th>Autostart</th><th>Uptime</th>' "$docker_page"
grep -q "'id' => \$container\['ID'\]" src/usr/local/emhttp/plugins/unraid.kubernetes/include/config.php
grep -q 'function positionDockerView()' src/usr/local/emhttp/plugins/unraid.kubernetes/scripts/kubernetes.js
grep -q 'function positionFullView()' src/usr/local/emhttp/plugins/unraid.kubernetes/scripts/kubernetes.js
grep -q 'closest(".status")' src/usr/local/emhttp/plugins/unraid.kubernetes/scripts/kubernetes.js
grep -q 'container.memory_usage' src/usr/local/emhttp/plugins/unraid.kubernetes/scripts/kubernetes.js
grep -q 'function setRefreshInterval' src/usr/local/emhttp/plugins/unraid.kubernetes/scripts/kubernetes.js
grep -q 'function syncRuntimeAdvancedView(view)' src/usr/local/emhttp/plugins/unraid.kubernetes/scripts/kubernetes.js
grep -q 'function alignRuntimeColumns(view)' src/usr/local/emhttp/plugins/unraid.kubernetes/scripts/kubernetes.js
grep -q 'function followRuntimeColumns()' src/usr/local/emhttp/plugins/unraid.kubernetes/scripts/kubernetes.js
grep -q 'change.dmK8s' src/usr/local/emhttp/plugins/unraid.kubernetes/scripts/kubernetes.js
grep -q 'icon="kubernetes.png"' plugin/unraid.kubernetes.plg.in
grep -q 'launch="Settings/KubernetesSettings"' plugin/unraid.kubernetes.plg.in
grep -qx 'Menu="Tasks:65"' src/usr/local/emhttp/plugins/unraid.kubernetes/Kubernetes.page
test "$(xmllint --xpath 'string(/Plugin/Project)' plugins/unraid-kubernetes.xml)" = 'https://github.com/DonnieDice/unraid-kubernetes'
test "$(xmllint --xpath 'string(/Plugin/ReadMe)' plugins/unraid-kubernetes.xml)" = 'https://github.com/DonnieDice/unraid-kubernetes#readme'
test -s src/usr/local/emhttp/plugins/unraid.kubernetes/README.md
test -s src/usr/local/emhttp/plugins/unraid.kubernetes/images/kubernetes.png

if command -v shellcheck >/dev/null 2>&1; then
  find src tools -type f \( -name '*.sh' -o -path '*/event/started' -o -path '*/event/stopping_docker' -o -name 'rc.*' \) -print0 | xargs -0 shellcheck
fi

if grep -RIE 'docker rm|kubectl delete|kubectl apply|cat[[:space:]].*token' src; then
  echo "Unsafe mutation or credential-output pattern found" >&2
  exit 1
fi

echo "Validation passed"
