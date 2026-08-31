#!/bin/bash
set -eu

plugin_dir="/boot/config/plugins/unraid.kubernetes"
settings="${plugin_dir}/settings.cfg"
k3d_source="${plugin_dir}/k3d-v5.9.0-linux-amd64"

mkdir -p "$plugin_dir"

if [[ ! -s "$settings" ]]; then
  cat >"$settings" <<'EOF'
PROVIDER="k3d"
CLUSTER_NAME="unraid-k3s"
DATA_ROOT="/mnt/user/appdata/unraid-kubernetes"
K3D_CONFIG="/mnt/user/appdata/unraid-kubernetes/config/k3d-unraid-k3s.yaml"
TOKEN_FILE="/mnt/user/appdata/unraid-kubernetes/secrets/server-token"
DATASTORE_DIR="/mnt/user/appdata/unraid-kubernetes/datastore"
STORAGE_DIR="/mnt/user/appdata/unraid-kubernetes/storage"
KUBECONFIG_DIR="/mnt/user/appdata/unraid-kubernetes/kubeconfig"
K3S_IMAGE="rancher/k3s:v1.36.1-k3s1"
KUBECONFIG="/mnt/user/appdata/unraid-kubernetes/kubeconfig/unraid-k3s.yaml"
SHOW_METRICS="yes"
SHOW_DASHBOARD_WIDGET="yes"
SHOW_KUBERNETES_PAGE="yes"
SHOW_DOCKER_HEADER="yes"
CPU_DISPLAY_UNIT="auto"
REFRESH_INTERVAL="15"
DASHBOARD_COLUMN="2"
EOF
  chmod 0600 "$settings"
fi
settings_owner=$(stat -c '%u' "$settings")
settings_mode=$(stat -c '%a' "$settings")
[[ ! -L "$settings" && "$settings_owner" == "0" && "$settings_mode" =~ ^[0-7][0145][0145]$ ]] || {
  echo "Refusing insecure plugin settings file" >&2
  exit 1
}

if [[ -s "$k3d_source" ]]; then
  install -m 0755 "$k3d_source" /usr/local/bin/k3d
elif [[ -s /boot/config/custom/bin/k3d ]]; then
  install -m 0755 /boot/config/custom/bin/k3d /usr/local/bin/k3d
fi

if docker info >/dev/null 2>&1; then
  nohup /etc/rc.d/rc.unraid-kubernetes start >>/var/log/unraid-kubernetes.log 2>&1 &
fi
