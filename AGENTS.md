# Agent Instructions

## Authority

- GitLab project `other-projects/unraid-kubernetes` is canonical.
- GitHub may be used later as a downstream release mirror only.
- Keep the plugin reusable; Tower-specific values belong in runtime settings.

## Safety

- Never print, package, or replace Kubernetes tokens or kubeconfig credentials.
- Never overwrite an existing k3d config, datastore, or local-path storage tree.
- Treat Unraid webGUI PHP as root-privileged code.
- Backend actions must use fixed command paths and explicit allowlists.
- Use Unraid's global CSRF handling for every POST action.
- Do not patch Unraid core webGUI files or nginx configuration.
- Do not deploy application workloads from this repository.

## Verification

- Run `tools/test.sh` before packaging.
- Validate PHP, JavaScript, shell, and PLG XML syntax.
- Test installation against a disposable package root before Tower.
- On Tower, verify read-only status before testing lifecycle controls.
