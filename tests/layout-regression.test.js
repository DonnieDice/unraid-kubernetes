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
