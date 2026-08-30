(function () {
  "use strict";

  if (window.UnraidKubernetesLoaded) return;
  window.UnraidKubernetesLoaded = true;

  const endpoint = "/plugins/unraid.kubernetes/include/api.php";
  const settingsEndpoint = "/plugins/unraid.kubernetes/include/settings.php";
  let refreshTimer = null;
  let requestPending = false;
  const observedViews = new WeakSet();
  const runtimeContainerNames = new Set();
  let dockerStatsObserver = null;

  const escapeHtml = (value) => String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");

  const views = () => Array.from(document.querySelectorAll("[data-dm-k8s-view]"));
  const visibleViews = () => views().filter((view) => view.getClientRects().length > 0);
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

  function renderFull(view, data) {
    const nodes = view.querySelector("[data-dm-k8s-nodes]");
    const namespaces = view.querySelector("[data-dm-k8s-namespaces]");
    const pods = view.querySelector("[data-dm-k8s-pods]");
    const warnings = view.querySelector("[data-dm-k8s-warnings]");
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

    if (pods) pods.innerHTML = data.pods.map((pod) => `<tr>
      <td>${escapeHtml(pod.namespace)}</td><td>${escapeHtml(pod.name)}</td><td>${escapeHtml(pod.ready)}</td>
      <td class="${pod.phase === "Running" ? "green-text" : "red-text"}">${escapeHtml(pod.phase)}</td>
      <td>${pod.restarts}</td><td data-dm-k8s-metrics ${data.metrics_enabled ? "" : "hidden"}>${escapeHtml(pod.cpu)}</td><td data-dm-k8s-metrics ${data.metrics_enabled ? "" : "hidden"}>${escapeHtml(pod.memory)}</td><td>${escapeHtml(pod.node)}</td>
    </tr>`).join("") || '<tr><td colspan="8">No pods reported.</td></tr>';

    if (warnings) warnings.innerHTML = data.warnings.length
      ? data.warnings.slice().reverse().map((warning) => `<article>
          <strong>${escapeHtml(warning.reason)}</strong>
          <span>${escapeHtml(warning.namespace)}/${escapeHtml(warning.object)}</span>
          <p>${escapeHtml(warning.message)}</p>
        </article>`).join("")
      : '<p class="green-text">No recent Kubernetes warning events.</p>';
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
      const stats = container.id ? `<td class="dm-k8s-advanced" data-dm-k8s-runtime-stats="${escapeHtml(container.id)}">
        <div class="dm-k8s-runtime-usage"><span data-dm-k8s-cpu>-</span><span data-dm-k8s-memory>-</span></div>
        <div class="usage-disk mm"><span data-dm-k8s-cpu-bar></span><span></span></div>
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
    syncRuntimeStats();
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

  function syncRuntimeStats() {
    document.querySelectorAll("[data-dm-k8s-runtime-stats]").forEach((target) => {
      const id = target.dataset.dmK8sRuntimeStats;
      const cpu = document.querySelector(`#docker_list .cpu-${id}`);
      const memory = document.querySelector(`#docker_list .mem-${id}`);
      const bar = document.querySelector(`#docker_list #cpu-${id}`);
      if (cpu) target.querySelector("[data-dm-k8s-cpu]").textContent = cpu.textContent;
      if (memory) target.querySelector("[data-dm-k8s-memory]").textContent = memory.textContent;
      if (bar) target.querySelector("[data-dm-k8s-cpu-bar]").style.width = bar.style.width;
    });
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

  function observeDockerStats() {
    const list = document.querySelector("#docker_list");
    if (!list || dockerStatsObserver) return;
    dockerStatsObserver = new MutationObserver(syncRuntimeStats);
    dockerStatsObserver.observe(list, {
      attributes: true,
      attributeFilter: ["style"],
      characterData: true,
      childList: true,
      subtree: true,
    });
  }

  function render(data) {
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
          ? data.warnings.slice().reverse().map((warning) => `<article><strong>${escapeHtml(warning.reason)}</strong><span>${escapeHtml(warning.namespace)}/${escapeHtml(warning.object)}: ${escapeHtml(warning.message)}</span></article>`).join("")
          : '<span class="green-text"><i class="fa fa-fw fa-check"></i> No recent Kubernetes warning events.</span>';
      }
      if (view.dataset.dmK8sView === "full") renderFull(view, data);
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
    button.disabled = true;
    window.jQuery.ajax({
      url: settingsEndpoint,
      method: "POST",
      dataType: "json",
      data: window.jQuery(form).serialize(),
    }).done(() => {
      message.textContent = "Settings saved.";
      refresh();
    }).fail((xhr) => {
      message.textContent = xhr.responseJSON?.error || `Settings save failed (${xhr.status})`;
    }).always(() => { button.disabled = false; });
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
    observeDockerStats();
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
      if (window.location.hash === "#dm-k8s-settings") document.querySelector("#dm-k8s-settings")?.setAttribute("open", "");
    }, { once: true });
  } else {
    observeViews();
    hideEmptyTabs();
    loadSettings();
    if (window.location.hash === "#dm-k8s-settings") document.querySelector("#dm-k8s-settings")?.setAttribute("open", "");
  }
  refreshTimer = window.setInterval(() => {
    if (document.visibilityState === "visible") refresh();
  }, 15000);
  window.addEventListener("beforeunload", () => {
    window.clearInterval(refreshTimer);
    mutationObserver.disconnect();
    dockerStatsObserver?.disconnect();
    observer.disconnect();
    window.jQuery(document).off("change.dmK8s", ".advancedview", advancedViewChanged);
  }, { once: true });
  window.addEventListener("resize", () => {
    document.querySelectorAll('[data-dm-k8s-view="docker"]').forEach(alignRuntimeColumns);
  });
})();
