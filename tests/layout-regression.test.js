const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");

const root = path.resolve(__dirname, "..");
const pluginRoot = path.join(
  root,
  "src/usr/local/emhttp/plugins/unraid.kubernetes",
);
const css = fs.readFileSync(
  path.join(pluginRoot, "styles/kubernetes.css"),
  "utf8",
);
const dashboard = fs.readFileSync(
  path.join(pluginRoot, "Kubernetes.Dashboard.page"),
  "utf8",
);
const docker = fs.readFileSync(
  path.join(pluginRoot, "Kubernetes.Docker.page"),
  "utf8",
);
const settings = fs.readFileSync(
  path.join(pluginRoot, "KubernetesSettings.page"),
  "utf8",
);

function declarationsFor(selector) {
  const declarations = {};
  const rules = css.matchAll(/([^{}]+)\{([^{}]*)\}/g);

  for (const rule of rules) {
    const selectors = rule[1].split(",").map((value) =>
      value.trim().replace(/\s+/g, " "),
    );
    if (!selectors.includes(selector)) continue;

    for (const declaration of rule[2].split(";")) {
      const separator = declaration.indexOf(":");
      if (separator === -1) continue;
      declarations[declaration.slice(0, separator).trim()] = declaration
        .slice(separator + 1)
        .trim();
    }
  }

  return declarations;
}

function assertDeclarations(selector, expected) {
  const actual = declarationsFor(selector);
  for (const [property, value] of Object.entries(expected)) {
    assert.equal(
      actual[property],
      value,
      `${selector} must keep ${property}: ${value}`,
    );
  }
}

test("Dashboard keeps native action alignment and compact height", () => {
  assert.match(dashboard, /\$mytiles\['unraid-kubernetes'\]\['column2'\]/);

  const primary = dashboard.indexOf('class="dm-k8s-dashboard-primary"');
  const summary = dashboard.indexOf('class="dm-k8s-dashboard-inline"');
  const actions = dashboard.indexOf('class="dm-k8s-dashboard-native-actions"');
  assert.ok(primary !== -1 && primary < summary && summary < actions);
  assert.match(
    dashboard,
    /fa-cog control[\s\S]*fa-external-link control/,
    "Settings and Open controls must remain native actions in that order",
  );

  assertDeclarations(".dm-k8s-dashboard-header", {
    "align-items": "center",
    display: "flex",
    width: "100%",
  });
  assertDeclarations(".dm-k8s-dashboard-native-actions", {
    "align-self": "flex-start",
    "line-height": "15px",
    "margin-left": "auto",
  });
  assertDeclarations(".dm-k8s-dashboard-primary", {
    height: "28px",
    "line-height": "28px",
  });
  assertDeclarations(
    ".dm-k8s-dashboard > tr:first-child .dm-k8s-dashboard-container",
    { "margin-right": "24px", width: "auto" },
  );
});

test("Docker panel keeps native anchors and full-width runtime table", () => {
  const title = docker.indexOf("data-dm-k8s-docker-title");
  const panel = docker.indexOf('data-dm-k8s-view="docker"');
  assert.notEqual(title, -1, "The native Docker title anchor must exist");
  assert.notEqual(panel, -1, "The Kubernetes Docker panel must exist");
  assert.ok(
    title < panel,
    "The native title must remain before the Kubernetes panel",
  );
  assert.match(
    docker,
    /data-dm-k8s-docker-health[\s\S]*href="\/Kubernetes"[\s\S]*href="\/Settings\/KubernetesSettings"/,
    "Health, Details, and Settings actions must retain native ordering",
  );
  assert.match(
    docker,
    /<th>Container<\/th><th>Kubernetes Role<\/th><th>Image<\/th><th>State<\/th><th class="dm-k8s-advanced">CPU &amp; Memory load<\/th><th>Autostart<\/th><th>Uptime<\/th>/,
  );

  assertDeclarations(".dm-k8s-docker", { width: "100%" });
  assertDeclarations(".dm-k8s-docker-heading", {
    "justify-content": "space-between",
    "padding-inline": "10px",
    width: "100%",
  });
  assertDeclarations(".dm-k8s-table-wrap table", {
    "max-width": "100%",
    width: "100%",
  });
  assertDeclarations(".dm-k8s-runtime-table", { "min-width": "0" });
});

test("Settings keeps native dl alignment without horizontal overflow", () => {
  assert.match(
    settings,
    /<dl>\s*<dt>Provider:<\/dt>\s*<dd><select name="provider">/,
  );
  assert.match(
    settings,
    /<dl class="dm-k8s-settings-footer">\s*<dt>&nbsp;<\/dt>\s*<dd><span data-dm-k8s-settings-message><\/span><button type="submit">Save Settings<\/button><\/dd>/,
  );

  assertDeclarations(".dm-k8s-settings-page", {
    "max-width": "100%",
    "min-width": "0",
    width: "100%",
  });
  assertDeclarations(".dm-k8s-settings-section input.dm-k8s-settings-path", {
    "max-width": "calc(100vw - 40px)",
    width: "520px",
  });
  assertDeclarations(".dm-k8s-settings-footer dd", {
    display: "flex",
    "padding-left": "0",
  });
  assertDeclarations(
    ".dm-k8s-settings-footer [data-dm-k8s-settings-message]:empty",
    { display: "none" },
  );
});

test("Health stays active-only with workload history tabs and grouped warnings", () => {
  const page = fs.readFileSync(
    path.join(pluginRoot, "Kubernetes.page"),
    "utf8",
  );
  const script = fs.readFileSync(
    path.join(pluginRoot, "scripts/kubernetes.js"),
    "utf8",
  );
  const backend = fs.readFileSync(
    path.join(pluginRoot, "include/config.php"),
    "utf8",
  );
  assert.match(page, /data-dm-k8s-jobs/);
  assert.match(page, /data-dm-k8s-job-tabs/);
  assert.match(script, /jobTab/);
  assert.match(script, /data-dm-k8s-job-tab/);
  assert.match(script, /renderWarningGroups/);
  assert.match(script, /No retained terminal workloads/);
  assert.match(page, /Workload history/);
  assert.match(backend, /'jobs' => \[\]/);
  assert.match(backend, /dm_k8s_warning_state/);
  assert.match(backend, /if \(dm_k8s_pod_is_terminal\(\$phase\)\)/);
  assert.match(css, /\.dm-k8s-tabs/);
  assert.match(css, /\.dm-k8s-recovered/);

  assertDeclarations(".dm-k8s-dashboard-warnings", {
    "overflow-y": "auto",
    "scrollbar-width": "none",
  });
});

test("Network column keeps compact class summary with accessible detail", () => {
  const page = fs.readFileSync(
    path.join(pluginRoot, "Kubernetes.page"),
    "utf8",
  );
  const script = fs.readFileSync(
    path.join(pluginRoot, "scripts/kubernetes.js"),
    "utf8",
  );
  const backend = fs.readFileSync(
    path.join(pluginRoot, "include/config.php"),
    "utf8",
  );
  const networkTest = fs.readFileSync(
    path.join(root, "tests/network-visibility.php"),
    "utf8",
  );

  // The pods table exposes the Network column after Node.
  const tableHeader = page.match(/<th>Namespace<\/th><th>Name<\/th><th>Ready<\/th><th>Status<\/th><th>Restarts<\/th>.*?<\/tr>/s);
  assert.ok(tableHeader, "Pods table header must exist");
  assert.match(tableHeader[0], /<th>Node<\/th><th>Network<\/th>/);

  // Compact cell: one exposure class plus a warning glyph count.
  assert.match(script, /exposureClassLabel/);
  assert.match(script, /cluster-internal/);
  assert.match(script, /loopback-only/);
  assert.match(script, /dm-k8s-net-warn/);

  // Accessible expandable detail with source naming.
  assert.match(script, /aria-expanded/);
  assert.match(script, /aria-controls/);
  assert.match(script, /dm-k8s-net-source/);
  assert.match(script, /renderNetworkDetail/);

  // Provider-unavailable degradation is explicit, not an empty list.
  assert.match(script, /provider-unavailable/);
  assert.match(script, /provider unavailable/);
  assert.match(backend, /provider-unavailable/);

  // Credential-bearing material never enters responses.
  for (const forbidden of [
    /get\s+secrets/i,
    /kubectl.*(secret|token)/i,
    /\$_ENV/,
    /getenv\('KUBECONFIG'\)/,
  ]) {
    assert.doesNotMatch(backend, forbidden);
    assert.doesNotMatch(script, forbidden);
  }
  assert.doesNotMatch(backend, /annotations/);

  // All five contract fixture cases are exercised.
  for (const fixtureCase of [
    "ClusterIP",
    "NodePort",
    "Ingress",
    "hostNetwork",
    "no-endpoint",
  ]) {
    assert.match(networkTest, new RegExp(fixtureCase, "i"), `fixture case ${fixtureCase} missing`);
  }
  assert.match(networkTest, /selector-mismatch/);
  assert.match(networkTest, /wildcard-binding/);
  assert.match(networkTest, /duplicate-host-ports/);

  // Classifier lives in the backend with the four ordered classes.
  assert.match(backend, /function dm_k8s_classify_exposure/);
  assert.match(backend, /'public', 'lan-only', 'loopback-only'/);

  // Detail styling keeps narrow-viewport safety.
  assert.match(css, /\.dm-k8s-net-detail/);
  assertDeclarations(".dm-k8s-net-detail", {
    "max-width": "100%",
    "min-width": "0",
    "overflow-wrap": "anywhere",
  });
});
