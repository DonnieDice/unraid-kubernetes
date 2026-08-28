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

  function setDashboardExpanded(view, expanded) {
    const row = view.querySelector("[data-dm-k8s-expanded-row]");
    const toggle = view.querySelector("[data-dm-k8s-dashboard-toggle]");
    if (!row || !toggle) return;
    row.hidden = !expanded;
    view.classList.toggle("dm-k8s-dashboard-expanded", expanded);
    toggle.title = expanded ? "Collapse Kubernetes summary" : "Expand Kubernetes summary";
    const icon = toggle.querySelector("i");
    if (icon) {
      icon.classList.toggle("fa-expand", !expanded);
      icon.classList.toggle("fa-compress", expanded);
    }
  }

  function renderFull(view, data) {
    const nodes = view.querySelector("[data-dm-k8s-nodes]");
    const namespaces = view.querySelector("[data-dm-k8s-namespaces]");
    const pods = view.querySelector("[data-dm-k8s-pods]");
    const warnings = view.querySelector("[data-dm-k8s-warnings]");
    if (nodes) nodes.innerHTML = data.nodes.map((node) => `<tr>
      <td>${escapeHtml(node.name)}</td>
      <td class="${node.ready ? "green-text" : "red-text"}">${node.ready ? "Ready" : "Not Ready"}</td>
      <td>${escapeHtml(node.roles.join(", "))}</td><td>${escapeHtml(node.version)}</td>
      <td>${escapeHtml(node.cpu)}</td><td>${escapeHtml(node.memory)}</td>
    </tr>`).join("") || '<tr><td colspan="6">No nodes reported.</td></tr>';

    if (namespaces) namespaces.innerHTML = data.namespaces.map((namespace) => `<tr>
      <td>${escapeHtml(namespace.name)}</td><td>${namespace.pods}</td><td>${namespace.ready}/${namespace.pods}</td>
    </tr>`).join("") || '<tr><td colspan="3">No namespaces reported.</td></tr>';

    if (pods) pods.innerHTML = data.pods.map((pod) => `<tr>
      <td>${escapeHtml(pod.namespace)}</td><td>${escapeHtml(pod.name)}</td><td>${escapeHtml(pod.ready)}</td>
      <td class="${pod.phase === "Running" ? "green-text" : "red-text"}">${escapeHtml(pod.phase)}</td>
      <td>${pod.restarts}</td><td>${escapeHtml(pod.node)}</td>
    </tr>`).join("") || '<tr><td colspan="6">No pods reported.</td></tr>';

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
      target.innerHTML = '<tr><td colspan="5">External cluster; no local runtime containers.</td></tr>';
      hideNativeRuntimeRows();
      return;
    }
    data.runtime.forEach((container) => runtimeContainerNames.add(container.name));
    target.innerHTML = data.runtime.map((container) => {
      const running = container.state === "running";
      const role = container.name.endsWith("-serverlb") ? "API load balancer" : "Control plane / worker";
      return `<tr>
        <td class="ct-name"><i class="fa fa-fw fa-cube ${running ? "green-text" : "red-text"}"></i> <strong>${escapeHtml(container.name)}</strong></td>
        <td>${role}</td><td>${escapeHtml(container.image)}</td>
        <td class="${running ? "green-text" : "red-text"}">${escapeHtml(container.state)}</td>
        <td>${escapeHtml(container.status || "-")}</td>
      </tr>`;
    }).join("") || '<tr><td colspan="5">No k3d runtime containers found.</td></tr>';
    hideNativeRuntimeRows();
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
        const nodesReady = data.nodes.filter((node) => node.ready).length;
        const podsReady = data.pods.filter((pod) => pod.phase === "Running" && pod.ready.split("/")[0] === pod.ready.split("/")[1]).length;
        if (inline) inline.innerHTML = `<span><i class="fa fa-fw fa-server"></i><strong>${nodesReady}/${data.nodes.length}</strong> nodes</span><span><i class="fa fa-fw fa-cube"></i><strong>${podsReady}/${data.pods.length}</strong> pods</span>`;
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
    const dashboardToggle = event.target.closest("[data-dm-k8s-dashboard-toggle]");
    if (dashboardToggle) {
      event.preventDefault();
      const view = dashboardToggle.closest("[data-dm-k8s-view]");
      const expanded = !view.classList.contains("dm-k8s-dashboard-expanded");
      setDashboardExpanded(view, expanded);
      window.localStorage.setItem("dm-k8s-dashboard-expanded", expanded ? "1" : "0");
      return;
    }
    const actionButton = event.target.closest("[data-dm-k8s-action]");
    if (actionButton) lifecycle(actionButton.dataset.dmK8sAction, actionButton);
  });
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
      if (view.dataset.dmK8sView === "dashboard") {
        setDashboardExpanded(view, window.localStorage.getItem("dm-k8s-dashboard-expanded") === "1");
      }
    });
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
    observer.disconnect();
  }, { once: true });
})();
