# CloudPanel Git Addon — Agent Guide

## Project Overview

This repository contains a **CloudPanel Git Addon**: a self-repairing addon that injects a **"Git" tab** into every site in the CloudPanel v2 administration UI. It allows site administrators to:

- Generate per-domain SSH key pairs (ED25519) for Git authentication.
- View and copy the public key to register as a deploy key in a Git repository.
- Configure a Git repository URL, branch, deploy path, and a custom bash deploy script.
- Test the SSH connection to the repository and list remote branches.
- Persist configuration per site in a JSON file owned by the site user.

> **Important note about `README.md`:** As of the latest commit, `README.md` still describes an unrelated **"CloudPanel Mail Addon"** (DKIM/SPF/DMARC). The actual codebase was switched to the Git addon in commit `27821f0`. Treat the source files (`scripts/clp-git-addon`, `GitController.php`, `git.html.twig`) and this `AGENTS.md` as the authoritative description. Do not rely on `README.md` for current behavior.

## Technology Stack

- **Backend:** PHP 8.x, Symfony 5/6 (CloudPanel's embedded Symfony app)
- **Frontend:** Twig templates + Vue.js 3 (loaded from CDN inside the template)
- **Shell / Operations:** Bash installer script (`scripts/clp-git-addon`)
- **Authentication:** SSH key pairs managed via `ssh-keygen`, Git operations via `git` + `GIT_SSH_COMMAND`
- **Target OS:** Ubuntu 22.04 / 24.04 with CloudPanel v2.5+

There is **no** `composer.json`, `package.json`, `pyproject.toml`, `Cargo.toml`, or similar build manifest in this repository. The addon is not a standalone application; it is a set of files that are copied into an existing CloudPanel installation.

## Project Structure

```
.
├── scripts/
│   └── clp-git-addon          # Bash CLI: install, repair, check, uninstall
├── src/
│   └── Controller/
│       └── Frontend/
│           └── GitController.php   # Symfony controller for the Git tab
├── templates/
│   └── Frontend/
│       └── Site/
│           └── git.html.twig       # Twig template + Vue.js UI
├── public/                    # Currently empty; CSS/JS assets are inherited from CloudPanel
├── .gitignore
├── LICENSE
└── README.md                  # Outdated (still describes the Mail addon)
```

## Build Process

There is **no compilation or package build step**. Development is done directly against the source files.

To "build" / deploy:

1. Clone or extract the repository to `/opt/clp-git-addon` on the target CloudPanel server.
2. Run the installer as root:
   ```bash
   /opt/clp-git-addon/scripts/clp-git-addon install
   ```
3. The installer copies PHP/Twig files into `/home/clp/htdocs/app/files/`, patches `config/routes.yaml`, patches `tab-container.html.twig`, installs an APT hook, and clears the Symfony cache.

For development iteration on a live server:

```bash
clp-git-addon repair      # Re-copy files and re-apply patches
clp-git-addon check       # Verify that all pieces are in place
```

## Runtime Architecture

CloudPanel is a Symfony application running under PHP-FPM as the `clp` user. The addon integrates without modifying existing CloudPanel source code:

| Component | Target location inside CloudPanel | Purpose |
|-----------|-----------------------------------|---------|
| `GitController.php` | `/home/clp/htdocs/app/files/src/Controller/Frontend/GitController.php` | Handles key generation, config I/O, SSH tests, branch listing |
| `git.html.twig` | `/home/clp/htdocs/app/files/templates/Frontend/Site/git.html.twig` | Renders the Git tab UI |
| Route entries | `/home/clp/htdocs/app/files/config/routes.yaml` | Symfony routes for the new tab |
| Tab entry | `templates/Frontend/Site/Partial/tab-container.html.twig` | Adds "Git" to the site navigation |

The addon uses CloudPanel's own entities (`SiteManager`, `UserManager`) and inherits CloudPanel's session-based authentication. Only logged-in CloudPanel users can access the Git tab.

### User Context and Privilege Escalation

Most file operations must run as the **site user** (e.g. `site-www-example`), not as the `clp` web user. The controller achieves this by shelling out with `sudo -u <siteUser>`. The `clp` user is expected to have passwordless sudo access as configured by CloudPanel itself in `/etc/sudoers.d/cloudpanel`.

### Update Safety

CloudPanel updates replace `/home/clp/htdocs/app/`. To survive updates:

- Source files are kept untouched at `/opt/clp-git-addon/`.
- An APT hook is installed at `/etc/apt/apt.conf.d/99-clp-git-addon`.
- After every `dpkg` operation, the hook runs `clp-git-addon repair --quiet`, which re-applies patches idempotently and clears the Symfony cache if needed.

## Code Organization

### `scripts/clp-git-addon`

Bash CLI script and installer. Key commands:

- `install` — copy files, patch routes/template, install APT hook, clear cache, install self to `/usr/local/bin/clp-git-addon`.
- `repair [--quiet]` — re-copy files and re-apply patches if missing.
- `check` — verify controller, template, routes, tab, and APT hook.
- `uninstall` — remove copied files, routes, tab entry, and APT hook.

The script defines paths, helpers (`log_ok`, `log_warn`, `log_err`, `check_root`, `is_patched`), and sed-based patch/unpatch logic.

### `src/Controller/Frontend/GitController.php`

Symfony controller extending `App\Controller\Controller`. Main responsibilities:

- `index()` — render the Git tab.
- `generateKey()` — generate an ED25519 SSH key pair via `ssh-keygen`, store filename in config.
- `testGit()` — run `git ls-remote` against a repository using the configured SSH key.
- `fetchBranches()` — list remote branches and detect `main`/`master` as default.
- `saveGitConfig()` — persist repo URL, branch, deploy path, and deploy script path.
- `saveConfig()` / `getConfig()` — read/write per-site JSON config as the site user.
- Helpers for file operations as the site user (`runAsUser`, `readFileAsUser`, `fileExistsAsUser`, `ensureKeyPermissions`, `executeGitCommand`, etc.).

Notable implementation details:

- Filenames are sanitized with `preg_replace('/[^a-zA-Z0-9_-]/', '', $filename)` to prevent path traversal.
- Configuration is stored at `/home/<siteUser>/git-config.json` with `600` permissions.
- Deploy scripts are saved as `/home/<siteUser>/deploy-<domain>.sh` and made executable.
- The controller uses `shell_exec()` extensively to execute commands as the site user.
- A few comments/messages are in Spanish (e.g. "Sanitizar filename", "No se encontró clave SSH por defecto").

### `templates/Frontend/Site/git.html.twig`

Twig template that extends `Frontend/layout.html.twig` and renders the Git tab. It:

- Uses Bootstrap 5 markup and CloudPanel's existing CSS (`site.css`).
- Loads Vue.js 3 from `https://unpkg.com/vue@3/dist/vue.global.prod.js`.
- Loads Ace Editor (`ace.min.js`) for the deploy script editor.
- Makes `fetch()` calls to the Symfony endpoints defined in `routes.yaml`.

There is no separate frontend build or bundler; the JavaScript is embedded directly in the Twig template.

## Development Conventions

- **Language:** PHP code uses English docblocks and identifiers, with occasional Spanish inline comments.
- **Code style:** No enforced linter (no PHPCS, ESLint, or Prettier configuration present). Existing code uses PSR-style-ish class structure but mixes spaces and formatting freely.
- **No dependency management files:** The addon relies on CloudPanel's installed Symfony and front-end assets.
- **File permissions:** Copied files are owned `clp:clp` and chmod `770` to match CloudPanel's conventions.
- **Git workflow:** Commits are small and descriptive. The repo origin is `git@github.com:Jefrien/cloudpanel-git-addon.git`.

## Testing Instructions

There is **no automated test suite** (no PHPUnit, Jest, Pest, etc.). Testing is manual:

1. On an Ubuntu CloudPanel server, clone to `/opt/clp-git-addon`.
2. Run `clp-git-addon install`.
3. Run `clp-git-addon check` and confirm all items show `[OK]`.
4. Open CloudPanel, navigate to a site, click the **Git** tab.
5. Generate an SSH key, copy the public key, and verify it is written to `/home/<siteUser>/.ssh/`.
6. Enter a Git repository URL and click **Test Connection**; confirm branches are listed.
7. Save configuration and verify `/home/<siteUser>/git-config.json` contents.
8. Simulate a CloudPanel update by removing the copied files/routes and run `clp-git-addon repair`; confirm patches are restored.

## Security Considerations

- **Command execution:** The controller uses `shell_exec()` and `sudo` with user-supplied values. Filenames are sanitized, but repository URLs and deploy scripts are passed to shell commands. Treat the Git tab as a privileged administrator feature.
- **SSH private keys:** Private keys remain in `/home/<siteUser>/.ssh/` with `600` permissions. They are never exposed in the UI.
- **Config files:** `git-config.json` and deploy scripts are owned by the site user and readable only by that user (`600` for config).
- **Authentication:** Access is gated by CloudPanel's session. No additional ACL logic is implemented in the addon.
- **Path traversal:** Filename sanitization is applied, but review any changes to file-path construction carefully.
- **APT hook:** The hook runs as root after package operations. Keep the installer script secure and verify its integrity after edits.
- **No CSRF token handling is currently visible** in the frontend `fetch()` calls (the previous Mail addon had CSRF tokens). If CloudPanel requires CSRF validation for POST routes, these endpoints may need updating.

## CLI Reference

```bash
clp-git-addon install      # Full installation
clp-git-addon check        # Verify all components are in place
clp-git-addon repair       # Re-apply patches (runs automatically after updates)
clp-git-addon uninstall    # Remove addon from CloudPanel (keeps site SSH keys/config)
```

## Files Modified on the Target Server

During installation the following CloudPanel files are modified:

- `/home/clp/htdocs/app/files/src/Controller/Frontend/GitController.php` (copied)
- `/home/clp/htdocs/app/files/templates/Frontend/Site/git.html.twig` (copied)
- `/home/clp/htdocs/app/files/config/routes.yaml` (routes appended)
- `/home/clp/htdocs/app/files/templates/Frontend/Site/Partial/tab-container.html.twig` (tab entry inserted)
- `/etc/apt/apt.conf.d/99-clp-git-addon` (APT hook created)
- `/usr/local/bin/clp-git-addon` (installer symlink/script copied)

Source files under `/opt/clp-git-addon/` are never touched by CloudPanel updates.
