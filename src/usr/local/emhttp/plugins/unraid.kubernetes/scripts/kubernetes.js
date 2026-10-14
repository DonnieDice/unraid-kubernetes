(function () {
  "use strict";

  if (window.UnraidKubernetesLoaded) return;
  window.UnraidKubernetesLoaded = true;

  const endpoint = "/plugins/unraid.kubernetes/include/api.php";
  const settingsEndpoint = "/plugins/unraid.kubernetes/include/settings.php";
  let refreshTimer = null;
  let refreshInterval = 15000;
  let requestPending = false;
  let jobTab = "failed";
  const observedViews = new WeakSet();
  const runtimeContainerNames = new Set();

  const escapeHtml = (value) => String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");

  const views = () => Array.from(document.querySelectorAll("[data-dm-k8s-view]"));
  const visibleViews = () => views().filter((view) => view.getClientRects().length > 0);
  const warningTime = (value) => {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? "Unknown time" : date.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
  };
  function setRefreshInterval(seconds) {
    const next = [5, 10, 15, 30, 60].includes(Number(seconds)) ? Number(seconds) * 1000 : 15000;
    if (refreshTimer && next === refreshInterval) return;
    refreshInterval = next;
    if (refreshTimer) window.clearInterval(refreshTimer);
    refreshTimer = window.setInterval(() => {
      if (document.visibilityState === "visible") refresh();
    }, refreshInterval);
  }
  function setMessage(view, text, level) {
    const target = view.querySelector("[data-dm-k8s-message]");
    if (!target) return;
    target.textContent = text;
    target.classList.remove("dm-k8s-healthy", "dm-k8s-error", "dm-k8s-inactive");
    target.classList.add("dm-k8s-message");
    if (level) target.classList.add(`dm-k8s-${level}`);
  }

  function renderSummary(view, data) {
    const target = view.querySelector("[data-dm-k8s-summary]");
    if (!target) return;
    const nodesReady = data.nodes.filter((node) => node.ready).length;
    const podsReady = data.pods.filter((pod) => pod.phase === "Running" && pod.ready.split("/")[0] === pod.ready.split("/")[1]).length;
    target.innerHTML = [
      ["Cluster", data.cluster.state],
      ["Nodes", `${nodesReady}/${data.nodes.length} Ready`],
      ["Pods", `${podsReady}/${data.pods.length} Ready`],
      ["Warnings (15m)", data.warnings.length],
    ].map(([label, value]) => `<div class="dm-k8s-stat"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`).join("");
  }

  const warningArticle = (warning) => `<article${warning.state === "recovered" ? ' class="dm-k8s-recovered"' : ""}>
      <strong>${escapeHtml(warning.reason)}</strong>
      <span>${escapeHtml(warning.namespace)}/${escapeHtml(warning.object)}${warning.state === "recovered" ? " · recovered" : ""}</span>
      <time datetime="${escapeHtml(warning.time)}">${escapeHtml(warningTime(warning.time))}</time>
      <p>${escapeHtml(warning.message)}</p>
    </article>`;
  const groupedWarnings = (warnings) => {
    const ordered = warnings.slice().reverse();
    const current = ordered.filter((warning) => warning.state !== "recovered");
    const recovered = ordered.filter((warning) => warning.state === "recovered");
    return { current, recovered };
  };

  const exposureClassLabel = {
    public: "Public",
    "lan-only": "LAN-only",
    "loopback-only": "Loopback-only",
    "cluster-internal": "Cluster-internal",
  };

  const renderNetworkValue = (network) => {
    const label = exposureClassLabel[network.class] ?? "Unknown";
    const warnings = network.warnings ?? [];
    const glyph = warnings.length
      ? ` <span class="dm-k8s-net-warn" role="img" aria-label="${warnings.length} network warnings">&#9888; ${warnings.length}</span>`
      : "";
    return `${escapeHtml(label)}${glyph}`;
  };

  const renderNetworkCell = (network) => {
    if (!network) return '<td>-</td>';
    if (network.state === "provider-unavailable") {
      return '<td><span class="dm-k8s-net-stale" title="k3d publication source unavailable">provider unavailable</span></td>';
    }
    return `<td class="dm-k8s-net-${escapeHtml(network.class)}">${renderNetworkValue(network)}</td>`;
  };

  const renderNetworkDetail = (network) => {
    if (!network) return "";
    if (network.state === "provider-unavailable") {
      return '<p class="dm-k8s-net-detail-stale">k3d publication source unavailable; exposure classes were not computed.</p>';
    }
    const lines = [];
    lines.push(`<p><strong>Pod IP</strong> <code>${escapeHtml(network.pod_ip || "-")}</code>` +
      (network.cni ? ` <span class="dm-k8s-net-source">(${escapeHtml(network.cni)})</span>` : "") + `</p>`);
    if (network.host_network) {
      const hostPorts = (network.host_ports ?? [])
        .map((port) => `${escapeHtml(String(port.host_ip || "0.0.0.0"))}:${escapeHtml(String(port.host_port))}&rarr;${escapeHtml(String(port.container_port))}/${escapeHtml(port.protocol)}`)
        .join(", ");
      lines.push(`<p><strong>hostNetwork</strong> <span class="dm-k8s-net-source">Pod</span> <code>${escapeHtml(network.host_ip || "-")}</code>${hostPorts ? ` ports ${hostPorts}` : ""}</p>`);
    }
    for (const service of network.services ?? []) {
      const ports = (service.ports ?? [])
        .map((port) => `${escapeHtml(String(port.port))}&rarr;${escapeHtml(String(port.target_port))}/${escapeHtml(port.protocol)}` +
          (port.node_port ? ` <span class="dm-k8s-net-nodeport">nodePort ${escapeHtml(String(port.node_port))}</span>` : ""))
        .join(", ");
      const endpoints = service.ready_count > 0
        ? `${service.ready_count} ready (${(service.ready_endpoints ?? []).map((address) => escapeHtml(address)).join(", ")})`
        : service.not_ready_count > 0
          ? "no ready endpoints"
          : "No ready endpoints";
      lines.push(`<p><strong>Service</strong> <code>${escapeHtml(service.name)}</code> <span class="dm-k8s-net-source">${escapeHtml(service.source)}</span>` +
        ` &middot; ${escapeHtml(service.type)}${ports ? ` &middot; ${ports}` : ""}` +
        ` &middot; <span class="dm-k8s-net-source">EndpointSlice</span> ${endpoints}</p>`);
      for (const route of service.ingress_routes ?? []) {
        lines.push(`<p><strong>Ingress</strong> <code>${escapeHtml(route.host)}${escapeHtml(route.path)}</code>` +
          ` <span class="dm-k8s-net-source">Ingress${route.class ? `/${escapeHtml(route.class)}` : ""}</span>` +
          `${service.external_path ? " &middot; served through Cloudflare tunnel (public path)" : ""}</p>`);
      }
      const publications = service.publications ?? [];
      const unpublished = service.unpublished_node_ports ?? [];
      if (publications.length) {
        const binds = publications
          .map((publication) => `${escapeHtml(publication.host_ip || "0.0.0.0")}:${escapeHtml(publication.host_port)}&rarr;${escapeHtml(publication.node_port)} <span class="dm-k8s-net-source">k3d</span>`)
          .join(", ");
        lines.push(`<p><strong>Host publications</strong> ${binds}</p>`);
      }
      for (const nodePort of unpublished) {
        lines.push(`<p><strong>NodePort</strong> ${escapeHtml(nodePort)} <span class="dm-k8s-net-source">Service</span> &middot; no host publication (cluster-internal)</p>`);
      }
    }
    if (!(network.services ?? []).length) {
      lines.push('<p><span class="dm-k8s-net-source">Service</span> No selector-matching Services.</p>');
    }
    for (const warning of network.warnings ?? []) {
      lines.push(`<p class="dm-k8s-net-warning-badge">&#9888; <strong>${escapeHtml(warning.rule)}</strong>` +
        ` <span class="dm-k8s-net-source">${escapeHtml(warning.source)}</span> &middot; ${escapeHtml(warning.message)}</p>`);
    }
    return lines.join("");
  };

  const renderWarningGroups = (warnings) =>
    warnings.length
      ? (() => {
        const groups = groupedWarnings(warnings);
        const currentHtml = groups.current.map(warningArticle).join("");
        const recoveredHtml = groups.recovered.length
          ? `<h4 class="dm-k8s-recovered-heading">Recovered</h4>${groups.recovered.map(warningArticle).join("")}`
          : "";
        return currentHtml + recoveredHtml;
      })()
      : '<p class="green-text">No recent Kubernetes warning events.</p>';

  const renderResources = (view, data) => {
    const target = view.querySelector("[data-dm-k8s-resources]");
    if (!target) return;
    const resources = data.resources;
    if (!resources) {
      target.innerHTML = '<tr><td colspan="4">Resource envelope unavailable.</td></tr>';
      return;
    }
    const host = resources.host ?? {};
    const k3d = resources.k3d ?? {};
    const capacity = resources.capacity ?? {};
    const allocatable = resources.allocatable ?? {};
    const workloads = resources.workloads ?? {};
    const formatCpu = (millicores) => millicores > 0 ? `${(millicores / 1000).toFixed(2)} cores` : "-";
    const formatMemory = (bytes) => bytes > 0 ? `${(bytes / 1024 ** 3).toFixed(1)} GiB` : "-";
    const hostCpu = `${host.logical_cpus ?? "-"} logical${host.sockets ? ` / ${host.sockets} socket(s)` : ""}`;
    const rows = [
      ["Host", hostCpu, formatMemory(host.memory_bytes ?? 0), host.source ?? "host"],
      ["Host reserve", `${workloads.host_reserve_cpus ?? "-"} CPU(s) reserved`, formatMemory(workloads.host_reserve_memory_bytes ?? 0), "settings"],
      ["k3d container", resources.readonly ? "read-only (external)" : k3d.cpu_limit_cores > 0 ? `${k3d.cpu_limit_cores} core(s)${k3d.cpuset_cpus ? ` · cpuset ${k3d.cpuset_cpus}` : ""}` : "unbounded", resources.readonly ? "-" : k3d.memory_limit_bytes > 0 ? formatMemory(k3d.memory_limit_bytes) : "unbounded", resources.readonly ? "external provider" : k3d.source ?? "docker"],
      ["Kubernetes capacity", formatCpu(capacity.cpu_millicores ?? 0), formatMemory(capacity.memory_bytes ?? 0), "kubectl nodes"],
      ["Kubernetes allocatable", formatCpu(allocatable.cpu_millicores ?? 0), formatMemory(allocatable.memory_bytes ?? 0), "kubectl nodes"],
      ["Workload requests", formatCpu(workloads.cpu_requests_millicores ?? 0), formatMemory(workloads.memory_requests_bytes ?? 0), "kubectl pods"],
      ["Workload limits", formatCpu(workloads.cpu_limits_millicores ?? 0), formatMemory(workloads.memory_limits_bytes ?? 0), "kubectl pods"],
    ];
    target.innerHTML = rows.map(([layer, cpu, memory, source]) => `<tr>
      <td>${escapeHtml(layer)}</td><td>${escapeHtml(cpu)}</td><td>${escapeHtml(memory)}</td><td>${escapeHtml(source)}</td>
    </tr>`).join("");
  };

  const renderAllocations = (view, data) => {
    const target = view.querySelector("[data-dm-k8s-allocations]");
    if (!target) return;
    const resources = data.resources;
    const allocations = (resources?.pods ?? []);
    if (!resources) {
      target.innerHTML = '<tr><td colspan="7">Workload allocation unavailable.</td></tr>';
      return;
    }
    if (!allocations.length) {
      target.innerHTML = '<tr><td colspan="7">No active workloads report resource requests.</td></tr>';
      return;
    }
    const formatCpu = (millicores) => millicores > 0 ? `${(millicores / 1000).toFixed(2)}` : "0";
    const formatMemory = (bytes) => bytes > 0 ? `${(bytes / 1024 ** 3).toFixed(2)} GiB` : "0";
    target.innerHTML = allocations.map((pod) => `<tr>
      <td>${escapeHtml(pod.namespace)}</td><td>${escapeHtml(pod.name)}</td><td>${escapeHtml(pod.qos)}</td>
      <td>${formatCpu(pod.cpu_requests_millicores)} / ${formatCpu(pod.cpu_limits_millicores)} cores</td>
      <td>${formatMemory(pod.memory_requests_bytes)} / ${formatMemory(pod.memory_limits_bytes)}</td>
      <td>${escapeHtml(pod.priority_class || String(pod.priority || 0))}</td>
      <td>${pod.exclusive_eligible && resources.exclusive_cpu_available ? escapeHtml((pod.assigned_cpus ?? []).join(", ") || "eligible") : "-"}</td>
    </tr>`).join("");
  };

  function renderFull(view, data) {
    const nodes = view.querySelector("[data-dm-k8s-nodes]");
    const namespaces = view.querySelector("[data-dm-k8s-namespaces]");
    const pods = view.querySelector("[data-dm-k8s-pods]");
    const warnings = view.querySelector("[data-dm-k8s-warnings]");
    const jobs = view.querySelector("[data-dm-k8s-jobs]");
    const jobTabs = view.querySelector("[data-dm-k8s-job-tabs]");
    view.querySelectorAll("[data-dm-k8s-metrics]").forEach((cell) => {
      cell.hidden = !data.metrics_enabled;
    });
    if (nodes) nodes.innerHTML = data.nodes.map((node) => `<tr>
      <td>${escapeHtml(node.name)}</td>
      <td class="${node.ready ? "green-text" : "red-text"}">${node.ready ? "Ready" : "Not Ready"}</td>
      <td>${escapeHtml(node.roles.join(", "))}</td><td>${escapeHtml(node.version)}</td>
      <td data-dm-k8s-metrics ${data.metrics_enabled ? "" : "hidden"}>${escapeHtml(node.cpu)}</td><td data-dm-k8s-metrics ${data.metrics_enabled ? "" : "hidden"}>${escapeHtml(node.memory)}</td>
    </tr>`).join("") || '<tr><td colspan="6">No nodes reported.</td></tr>';

    if (namespaces) namespaces.innerHTML = data.namespaces.map((namespace) => `<tr>
      <td>${escapeHtml(namespace.name)}</td><td>${namespace.pods}</td><td>${namespace.ready}/${namespace.pods}</td>
      <td data-dm-k8s-metrics ${data.metrics_enabled ? "" : "hidden"}>${escapeHtml(namespace.cpu)}</td><td data-dm-k8s-metrics ${data.metrics_enabled ? "" : "hidden"}>${escapeHtml(namespace.memory)}</td>
    </tr>`).join("") || '<tr><td colspan="5">No namespaces reported.</td></tr>';

    if (pods) {
      const rows = [];
      data.pods.forEach((pod, index) => {
        const network = pod.network ?? null;
        const detailId = `dm-k8s-net-${index}`;
        const hasDetail = network && network.state !== "provider-unavailable";
        rows.push(`<tr data-dm-k8s-pod-index="${index}">`);
        rows.push(`<td>${escapeHtml(pod.namespace)}</td><td>${escapeHtml(pod.name)}</td><td>${escapeHtml(pod.ready)}</td>`);
        rows.push(`<td class="${pod.phase === "Running" ? "green-text" : "red-text"}">${escapeHtml(pod.phase)}</td>`);
        rows.push(`<td>${pod.restarts}</td><td data-dm-k8s-metrics ${data.metrics_enabled ? "" : "hidden"}>${escapeHtml(pod.cpu)}</td><td data-dm-k8s-metrics ${data.metrics_enabled ? "" : "hidden"}>${escapeHtml(pod.memory)}</td><td>${escapeHtml(pod.node)}</td>`);
        rows.push(hasDetail
          ? `<td class="dm-k8s-net-cell"><button type="button" class="dm-k8s-net-toggle" aria-expanded="false" aria-controls="${detailId}" data-dm-k8s-net-toggle="${index}" title="Show network detail">${renderNetworkValue(network)}</button></td>`
          : renderNetworkCell(network));
        rows.push("</tr>");
        if (hasDetail) {
          rows.push(`<tr class="dm-k8s-net-detail-row" id="${detailId}" hidden>`);
          rows.push('<td colspan="9" class="dm-k8s-net-detail">');
          rows.push(renderNetworkDetail(network));
          rows.push("</td></tr>");
        }
      });
      pods.innerHTML = rows.join("") || '<tr><td colspan="9">No pods reported.</td></tr>';
      pods.querySelectorAll("[data-dm-k8s-net-toggle]").forEach((button) => {
        button.addEventListener("click", () => {
          const detail = document.getElementById(button.getAttribute("aria-controls"));
          const expanded = button.getAttribute("aria-expanded") === "true";
          button.setAttribute("aria-expanded", expanded ? "false" : "true");
          if (detail) detail.hidden = expanded;
        });
      });
    }

    if (warnings) warnings.innerHTML = renderWarningGroups(data.warnings);

    if (jobTabs || jobs) {
      const history = Array.isArray(data.jobs) ? data.jobs : [];
      const failed = history.filter((job) => job.status === "Failed");
      const completed = history.filter((job) => job.status !== "Failed");
      if (jobTabs) {
        jobTabs.innerHTML = `<div class="dm-k8s-tabs" role="tablist" aria-label="Workload history">
          <button type="button" role="tab" data-dm-k8s-job-tab="failed" aria-selected="${jobTab === "failed"}">Failed (${failed.length})</button>
          <button type="button" role="tab" data-dm-k8s-job-tab="completed" aria-selected="${jobTab === "completed"}">Completed (${completed.length})</button>
        </div>`;
        jobTabs.querySelectorAll("[data-dm-k8s-job-tab]").forEach((button) => {
          button.addEventListener("click", () => {
            jobTab = button.getAttribute("data-dm-k8s-job-tab");
            refresh();
          });
        });
      }
      if (jobs) {
        const rows = (jobTab === "failed" ? failed : completed).map((job) => `<tr>
          <td>${escapeHtml(job.namespace)}</td><td>${escapeHtml(job.name)}</td>
          <td class="${job.status === "Failed" ? "red-text" : "green-text"}">${escapeHtml(job.status)}</td>
          <td>${escapeHtml(String(job.restarts))}</td><td>${escapeHtml(warningTime(job.time))}</td>
        </tr>`).join("");
        jobs.innerHTML = rows || '<tr><td colspan="5">No retained terminal workloads.</td></tr>';
      }
    }
  }

  function renderRuntime(view, data) {
    const target = view.querySelector("[data-dm-k8s-runtime]");
    const health = view.querySelector("[data-dm-k8s-docker-health]");
    if (!target) return;
    const nodesReady = data.nodes.filter((node) => node.ready).length;
    const podsReady = data.pods.filter((pod) => pod.phase === "Running" && pod.ready.split("/")[0] === pod.ready.split("/")[1]).length;
    if (health) health.textContent = `${nodesReady}/${data.nodes.length} nodes · ${podsReady}/${data.pods.length} pods`;
    runtimeContainerNames.clear();
    if (data.cluster.provider !== "k3d") {
      target.innerHTML = '<tr><td colspan="7">External cluster; no local runtime containers.</td></tr>';
      syncRuntimeAdvancedView(view);
      hideNativeRuntimeRows();
      return;
    }
    data.runtime.forEach((container) => runtimeContainerNames.add(container.name));
    target.innerHTML = data.runtime.map((container) => {
      const running = container.state === "running";
      const role = container.name.endsWith("-serverlb") ? "API load balancer" : "Control plane / worker";
      const cpu = Number.parseFloat(container.cpu);
      const cpuWidth = Number.isFinite(cpu) ? Math.min(Math.max(cpu, 0), 100) : 0;
      const stats = container.id ? `<td class="dm-k8s-advanced" title="${escapeHtml(container.memory_usage)}">
        <div class="dm-k8s-runtime-usage"><span>${escapeHtml(container.cpu)}</span><span>${escapeHtml(container.memory)}</span></div>
        <div class="usage-disk mm"><span style="width:${cpuWidth}%"></span><span></span></div>
      </td>` : '<td class="dm-k8s-advanced">-</td>';
      return `<tr>
        <td class="ct-name"><i class="fa fa-fw fa-cube ${running ? "green-text" : "red-text"}"></i> <strong>${escapeHtml(container.name)}</strong></td>
        <td>${role}</td><td>${escapeHtml(container.image)}</td>
        <td class="${running ? "green-text" : "red-text"}">${escapeHtml(container.state)}</td>
        ${stats}
        <td>k3d managed</td>
        <td>${escapeHtml(container.status || "-")}</td>
      </tr>`;
    }).join("") || '<tr><td colspan="7">No k3d runtime containers found.</td></tr>';
    syncRuntimeAdvancedView(view);
    alignRuntimeColumns(view);
    hideNativeRuntimeRows();
  }

  function syncRuntimeAdvancedView(view) {
    const toggle = document.querySelector(".advancedview");
    const advanced = toggle
      ? toggle.checked
      : window.jQuery?.cookie?.("docker_listview_mode") === "advanced";
    view.querySelectorAll(".dm-k8s-advanced").forEach((cell) => {
      cell.style.display = "table-cell";
      cell.style.padding = advanced ? "" : "0";
      cell.style.visibility = advanced ? "visible" : "hidden";
    });
  }

  function alignRuntimeColumns(view) {
    const table = view.querySelector(".dm-k8s-runtime-table");
    const nativeTable = document.querySelector("#docker_containers");
    const nativeHeaders = Array.from(nativeTable?.querySelectorAll(":scope > thead > tr > th") || []);
    const cpu = nativeHeaders.find((header) => header.textContent.includes("CPU & Memory"));
    const autostart = nativeHeaders.find((header) => header.textContent.trim() === "Autostart");
    const uptime = nativeHeaders.find((header) => header.textContent.trim() === "Uptime");
    if (!table || !nativeTable || !cpu || !autostart || !uptime) return;

    const nativeRect = nativeTable.getBoundingClientRect();
    const cpuRect = cpu.getBoundingClientRect();
    const autostartRect = autostart.getBoundingClientRect();
    const uptimeRect = uptime.getBoundingClientRect();
    const advanced = document.querySelector(".advancedview")?.checked;
    const availableWidth = view.querySelector(".dm-k8s-table-wrap")?.clientWidth || nativeRect.width;
    const tableWidth = Math.min(nativeRect.width, availableWidth);
    const scale = tableWidth / nativeRect.width;
    const leadingWidth = ((advanced ? cpuRect.left : autostartRect.left) - nativeRect.left) * scale;
    const leadingColumns = ["container", "role", "image", "state"];
    const leadingRatios = [0.34, 0.23, 0.29, 0.14];

    table.style.tableLayout = "fixed";
    table.style.width = `${tableWidth}px`;
    leadingColumns.forEach((name, index) => {
      table.querySelector(`[data-dm-k8s-col="${name}"]`).style.width = `${leadingWidth * leadingRatios[index]}px`;
    });
    table.querySelector('[data-dm-k8s-col="load"]').style.width = advanced ? `${cpuRect.width * scale}px` : "0px";
    table.querySelector('[data-dm-k8s-col="autostart"]').style.width = `${autostartRect.width * scale}px`;
    table.querySelector('[data-dm-k8s-col="uptime"]').style.width = `${uptimeRect.width * scale}px`;
  }

  function followRuntimeColumns() {
    const started = performance.now();
    const follow = () => {
      document.querySelectorAll('[data-dm-k8s-view="docker"]').forEach(alignRuntimeColumns);
      if (performance.now() - started < 1200) window.requestAnimationFrame(follow);
    };
    window.requestAnimationFrame(follow);
  }

  function hideNativeRuntimeRows() {
    document.querySelectorAll("#docker_list tr").forEach((row) => {
      const name = row.querySelector(".ct-name .appname")?.textContent.trim();
      if (name && runtimeContainerNames.has(name)) {
        row.hidden = true;
        row.dataset.dmK8sMoved = "true";
      } else if (row.dataset.dmK8sMoved === "true") {
        row.hidden = false;
        delete row.dataset.dmK8sMoved;
      }
    });
  }

  function hideEmptyTabs(tabs = document.querySelector(".tabs")) {
    if (tabs && tabs.childElementCount === 0 && tabs.textContent.trim() === "") {
      tabs.hidden = true;
    }
  }

  function positionDockerView() {
    const view = document.querySelector('[data-dm-k8s-view="docker"]');
    const title = document.querySelector("[data-dm-k8s-docker-title]");
    const table = document.querySelector("#docker_containers");
    const content = table?.closest(".content");
    const dockerTitle = content?.querySelector(":scope > .title:not([data-dm-k8s-docker-title])");
    if (!view || !title || !content || !dockerTitle) return;
    if (title.parentElement !== content || title.nextElementSibling !== view || view.nextElementSibling !== dockerTitle) {
      content.insertBefore(title, dockerTitle);
      content.insertBefore(view, dockerTitle);
    }
    const status = document.querySelector(".advancedview")?.closest(".status");
    if (status && status.parentElement !== title) title.appendChild(status);
  }

  function positionFullView() {
    const view = document.querySelector('[data-dm-k8s-view="full"]');
    view?.closest(".content")?.querySelector(":scope > .title")?.classList.add("dm-k8s-plugin-title");
    const assetWrapper = view?.previousElementSibling;
    if (assetWrapper?.matches("p") && assetWrapper.querySelector('link[href*="unraid.kubernetes"]')) {
      assetWrapper.classList.add("dm-k8s-plugin-assets");
    }
  }

  function render(data) {
    setRefreshInterval(data.refresh_interval);
    views().forEach((view) => {
      const running = data.cluster.state === "Running";
      const degraded = data.cluster.state === "Degraded";
      const provider = data.cluster.provider === "k3d" ? "local k3d" : "external K3S";
      const isDashboard = view.dataset.dmK8sView === "dashboard";
      const message = data.error || (isDashboard ? data.cluster.state : `${data.cluster.name} is ${data.cluster.state} · ${provider}`);
      setMessage(view, message, running ? "healthy" : (degraded ? "error" : "inactive"));
      renderSummary(view, data);
      if (isDashboard) {
        const inline = view.querySelector("[data-dm-k8s-inline-summary]");
        const warnings = view.querySelector("[data-dm-k8s-dashboard-warnings]");
        const nodesReady = data.nodes.filter((node) => node.ready).length;
        const podsReady = data.pods.filter((pod) => pod.phase === "Running" && pod.ready.split("/")[0] === pod.ready.split("/")[1]).length;
        if (inline) inline.innerHTML = `<span><i class="fa fa-fw fa-server"></i><strong>${nodesReady}/${data.nodes.length}</strong> nodes</span><span><i class="fa fa-fw fa-cube"></i><strong>${podsReady}/${data.pods.length}</strong> pods</span>`;
        if (warnings) warnings.innerHTML = data.warnings.length
          ? renderWarningGroups(data.warnings)
          : '<span class="green-text"><i class="fa fa-fw fa-check"></i> No recent Kubernetes warning events.</span>';
      }
      if (view.dataset.dmK8sView === "full") {
        renderFull(view, data);
        renderResources(view, data);
        renderAllocations(view, data);
      }
      if (view.dataset.dmK8sView === "docker") renderRuntime(view, data);
      view.querySelectorAll("[data-dm-k8s-action]").forEach((button) => {
        button.hidden = !data.cluster.managed;
      });
    });
  }

  async function refresh() {
    if (requestPending || visibleViews().length === 0) return;
    requestPending = true;
    try {
      const response = await fetch(endpoint, { cache: "no-store", credentials: "same-origin" });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || `HTTP ${response.status}`);
      render(data);
    } catch (error) {
      views().forEach((view) => setMessage(view, `Status unavailable: ${error.message}`, "error"));
    } finally {
      requestPending = false;
    }
  }

  function lifecycle(action, button) {
    if (!window.confirm(`${action[0].toUpperCase() + action.slice(1)} the Kubernetes cluster?`)) return;
    button.disabled = true;
    window.jQuery.ajax({
      url: endpoint,
      method: "POST",
      dataType: "json",
      data: { action },
    }).done(() => {
      views().forEach((view) => setMessage(view, `${action} requested...`, "inactive"));
      window.setTimeout(refresh, 3000);
    }).fail((xhr) => {
      const message = xhr.responseJSON?.error || `Lifecycle request failed (${xhr.status})`;
      views().forEach((view) => setMessage(view, message, "error"));
    }).always(() => { button.disabled = false; });
  }

  async function loadSettings() {
    const form = document.querySelector("[data-dm-k8s-settings-form]");
    if (!form) return;
    const message = form.querySelector("[data-dm-k8s-settings-message]");
    try {
      const response = await fetch(settingsEndpoint, { cache: "no-store", credentials: "same-origin" });
      const settings = await response.json();
      if (!response.ok) throw new Error(settings.error || `HTTP ${response.status}`);
      Object.entries(settings).forEach(([name, value]) => {
        if (form.elements[name]) form.elements[name].value = value;
      });
    } catch (error) {
      message.textContent = `Settings unavailable: ${error.message}`;
    }
  }

  function saveSettings(form) {
    const message = form.querySelector("[data-dm-k8s-settings-message]");
    const button = form.querySelector('button[type="submit"]');
    const providerField = form.elements.provider;
    const envelopeFields = ["cpu_limit_cores", "memory_limit_mib", "host_reserve_cpus", "host_reserve_memory_mib"];
    const envelopeChanged = envelopeFields.some((name) => {
      const field = form.elements[name];
      return field && field.dataset.dmK8sOriginal !== String(field.value ?? "");
    });
    if (providerField && providerField.value !== "k3d" && envelopeChanged) {
      message.textContent = "Resource envelope settings apply to plugin-managed k3d clusters only; external clusters stay read-only.";
      return;
    }
    if (envelopeChanged) {
      const preview = form.querySelector("[data-dm-k8s-preview]");
      const rows = form.querySelector("[data-dm-k8s-preview-rows]");
      const violations = (JSON.parse(preview?.dataset.dmK8sViolations || "[]") || []);
      if (violations.length) {
        message.textContent = `Cannot save: ${violations[0]}`;
        return;
      }
    }
    button.disabled = true;
    window.jQuery.ajax({
      url: settingsEndpoint,
      method: "POST",
      dataType: "json",
      data: window.jQuery(form).serialize(),
    }).done(() => {
      message.textContent = "Settings saved. Changed resource limits apply on the next cluster restart.";
      refresh();
    }).fail((xhr) => {
      message.textContent = xhr.responseJSON?.error || `Settings save failed (${xhr.status})`;
    }).always(() => { button.disabled = false; });
  }

  function updateResourcePreview(form) {
    const preview = form.querySelector("[data-dm-k8s-preview]");
    const rows = form.querySelector("[data-dm-k8s-preview-rows]");
    if (!preview || !rows) return;
    const cores = form.elements.cpu_limit_cores?.value || "0";
    const memory = form.elements.memory_limit_mib?.value || "0";
    const message = form.querySelector("[data-dm-k8s-settings-message]");
    window.jQuery.ajax({
      url: settingsEndpoint,
      method: "GET",
      dataType: "json",
      data: { preview: "1", cpu_limit_cores: cores, memory_limit_mib: memory },
    }).done((settings) => {
      const previewData = settings.preview || {};
      const violations = previewData.violations || [];
      preview.dataset.dmK8sViolations = JSON.stringify(violations);
      preview.hidden = false;
      const proposed = previewData.proposed || {};
      const host = previewData.host || {};
      const fmtMemory = (bytes) => bytes > 0 ? `${(bytes / 1024 ** 3).toFixed(1)} GiB` : "unbounded";
      const entries = [
        ["Current CPU limit", String(previewData.current?.cpu_limit_cores || 0) + (previewData.current?.cpu_limit_cores > 0 ? " core(s)" : " (unbounded)")],
        ["Proposed CPU limit", String(proposed.cpu_limit_cores || 0) + (proposed.cpu_limit_cores > 0 ? " core(s)" : " (unbounded)")],
        ["Current memory limit", fmtMemory(previewData.current?.memory_limit_bytes || 0)],
        ["Proposed memory limit", fmtMemory(proposed.memory_limit_bytes || 0)],
        ["Host logical CPUs", String(host.logical_cpus ?? "-")],
        ["Host memory", fmtMemory(host.memory_bytes ?? 0)],
        ["Host reserve", `${host.host_reserve_cpus ?? "-"} CPU(s) · ${fmtMemory(host.host_reserve_memory_bytes ?? 0)}`],
      ];
      rows.innerHTML = entries.map(([label, value]) => `<dt>${escapeHtml(label)}:</dt><dd>${escapeHtml(value)}</dd>`).join("")
        + (violations.length
          ? `<dt>Blocked:</dt><dd><ul class="dm-k8s-preview-violations">${violations.map((v) => `<li>${escapeHtml(v)}</li>`).join("")}</ul></dd>`
          : '<dt class="green-text">Validation:</dt><dd class="green-text">No unsafe conditions detected.</dd>');
      if (message && !message.textContent.startsWith("Settings saved")) {
        message.textContent = violations.length ? "Preview shows blocking conditions; apply is disabled." : "";
      }
    });
  }

  function watchResourcePreview() {
    const form = document.querySelector("[data-dm-k8s-settings-form]");
    if (!form) return;
    const inputs = Array.from(form.querySelectorAll("[data-dm-k8s-preview-input]"));
    if (!inputs.length) return;
    const storeOriginals = () => inputs.forEach((input) => { input.dataset.dmK8sOriginal = String(input.value ?? ""); });
    let debounce = null;
    inputs.forEach((input) => {
      input.addEventListener("input", () => {
        window.clearTimeout(debounce);
        debounce = window.setTimeout(() => updateResourcePreview(form), 400);
      });
    });
    form.addEventListener("submit", storeOriginals, { once: true });
    window.setTimeout(() => {
      storeOriginals();
      updateResourcePreview(form);
    }, 0);
  }

  document.addEventListener("click", (event) => {
    const actionButton = event.target.closest("[data-dm-k8s-action]");
    if (actionButton) lifecycle(actionButton.dataset.dmK8sAction, actionButton);
  });
  function advancedViewChanged() {
    document.querySelectorAll('[data-dm-k8s-view="docker"]').forEach(syncRuntimeAdvancedView);
    followRuntimeColumns();
  }
  window.jQuery(document).on("change.dmK8s", ".advancedview", advancedViewChanged);
  document.addEventListener("submit", (event) => {
    const form = event.target.closest("[data-dm-k8s-settings-form]");
    if (!form) return;
    event.preventDefault();
    saveSettings(form);
  });

  const observer = new IntersectionObserver((entries) => {
    if (entries.some((entry) => entry.isIntersecting)) refresh();
  });

  function observeViews() {
    let added = false;
    views().forEach((view) => {
      if (observedViews.has(view)) return;
      observedViews.add(view);
      added = true;
      observer.observe(view);
    });
    positionDockerView();
    positionFullView();
    if (added) refresh();
  }

  const mutationObserver = new MutationObserver(() => {
    observeViews();
    hideNativeRuntimeRows();
    hideEmptyTabs();
  });
  mutationObserver.observe(document.documentElement, { childList: true, subtree: true });
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => {
      observeViews();
      hideEmptyTabs();
      loadSettings();
      watchResourcePreview();
      if (window.location.hash === "#dm-k8s-settings") document.querySelector("#dm-k8s-settings")?.setAttribute("open", "");
    }, { once: true });
  } else {
    observeViews();
    hideEmptyTabs();
    loadSettings();
    watchResourcePreview();
    if (window.location.hash === "#dm-k8s-settings") document.querySelector("#dm-k8s-settings")?.setAttribute("open", "");
  }
  setRefreshInterval(15);
  window.addEventListener("beforeunload", () => {
    window.clearInterval(refreshTimer);
    mutationObserver.disconnect();
    observer.disconnect();
    window.jQuery(document).off("change.dmK8s", ".advancedview", advancedViewChanged);
  }, { once: true });
  window.addEventListener("resize", () => {
    document.querySelectorAll('[data-dm-k8s-view="docker"]').forEach(alignRuntimeColumns);
  });
})();
