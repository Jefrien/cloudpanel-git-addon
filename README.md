# CloudPanel Git Addon

**Git deployment management for CloudPanel v2** -- directly in the CloudPanel UI.

CloudPanel is a lightweight server control panel without built-in Git deployment features. This addon fills the gap: it adds a **"Git" tab** to every site in CloudPanel, where you can generate SSH keys, register the public key as a deploy key in your repository, and configure repository URL, branch, deploy path, and a custom bash deploy script.

![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)
![CloudPanel: v2.5+](https://img.shields.io/badge/CloudPanel-v2.5%2B-orange)
![Ubuntu: 24.04 / 22.04](https://img.shields.io/badge/Ubuntu-24.04%20%7C%2022.04-purple)

---

## What This Addon Does

When you host websites on CloudPanel, you often need a reliable way to deploy code from a private Git repository. Manually managing SSH keys, repository access, and deploy scripts on the server can be tedious and error-prone.

This addon:

- Adds a **"Git" tab** to every site in CloudPanel's web interface
- **Generates ED25519 SSH key pairs** per domain with one click
- Displays the **public key** so you can copy it as a deploy key in your Git provider
- Lets you configure the **repository URL**, **branch**, **deploy path**, and a **custom deploy script**
- **Tests the SSH connection** to the repository and lists remote branches
- **Persists configuration per site** in a JSON file owned by the site user
- **Survives CloudPanel updates** via an APT hook that re-applies patches automatically

> **Note:** This addon does not install Git for you. CloudPanel servers usually ship with `git` and `ssh-keygen`; the addon uses these existing tools to manage SSH authentication and repository access. If your server uses HTTPS-only repositories, the SSH-based workflow will need manual adjustment.

---

## Requirements

- **CloudPanel v2.5+** on Ubuntu 24.04 or 22.04
- **Root access** to the server
- `git` and `ssh-keygen` available on the server
- A Git repository you can register the deploy key with

---

## Installation

### 1. Clone to your server

```bash
git clone https://github.com/Jefrien/cloudpanel-git-addon.git /opt/clp-git-addon
```

Or install a specific release (recommended for production):

```bash
wget -qO- https://github.com/Jefrien/cloudpanel-git-addon/archive/refs/tags/v1.0.9.tar.gz | tar xz -C /opt/ && mv /opt/cloudpanel-git-addon-1.0.9 /opt/clp-git-addon
```

### 2. Run the installer

```bash
/opt/clp-git-addon/scripts/clp-git-addon install
```

This will:
- Copy the addon files into CloudPanel
- Add the "Git" tab to the site navigation
- Register the necessary routes
- Install an APT hook for automatic repair after CloudPanel updates
- Clear the Symfony cache

### 3. Verify

```bash
clp-git-addon check
```

All items should show `[OK]`.

---

## Usage

1. Open CloudPanel and navigate to any site
2. Click the **"Git"** tab
3. Click **"Generate SSH Key"** if no key exists yet
4. Copy the displayed public key and add it as a **deploy key** in your Git repository settings
5. Enter the **repository URL** (SSH format, e.g. `git@github.com:username/repo.git`)
6. Click **"Test Connection"** to verify SSH access and list remote branches
7. Select the **branch**, enter the **deploy path**, and optionally provide a **deploy script**
8. Click **"Save Configuration"**

---

## Webhook Configuration

The addon runs a small webhook server on port `9000`. Git providers need a publicly reachable URL to send push events to. You have two options to expose the webhook endpoint:

### Option 1: Open port 9000 in CloudPanel firewall

Open port `9000` in your CloudPanel firewall settings so the webhook server is reachable directly at:

```
http://<your-server-ip>:9000/hooks/<domainName>
```

Make sure your hosting provider's network-level firewall (if any) also allows traffic on port `9000`.

### Option 2: Use a reverse proxy with a domain

For a cleaner URL and to avoid opening an extra port, configure a reverse proxy on an existing domain (for example, `git-hooks.yourserver.com`) that proxies requests to `http://127.0.0.1:9000`. Example Nginx location block:

```nginx
location /hooks/ {
    proxy_pass http://127.0.0.1:9000/hooks/;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
}
```

With this setup, your Git provider webhook URL becomes:

```
https://git-hooks.yourserver.com/hooks/<domainName>
```

> **Note:** The addon generates the webhook URL using the server's primary IP address and port `9000` by default. If you use a reverse proxy, replace the generated URL with your proxy domain when configuring the webhook in your Git provider.

---

## How It Works

### Architecture

The addon integrates into CloudPanel's Symfony application without modifying any existing code:

| Component | Location | Purpose |
|-----------|----------|---------|
| `GitController.php` | `src/Controller/Frontend/` | Handles key generation, config I/O, SSH tests, branch listing |
| `git.html.twig` | `templates/Frontend/Site/` | The "Git" tab UI |
| Route entries | `config/routes.yaml` | URL routing for the new tab |
| Tab entry | `tab-container.html.twig` | Adds "Git" to the navigation |

### Permissions

CloudPanel runs its PHP-FPM process as the `clp` user. The `clp` user has passwordless sudo access (configured by CloudPanel itself in `/etc/sudoers.d/cloudpanel`). The addon uses `sudo -u <siteUser>` for operations that must run as the site user:

- Generating and reading SSH key pairs
- Reading and writing per-site configuration
- Executing `git` commands with the site's SSH identity
- Saving and executing deploy scripts

**SSH private keys remain in `/home/<siteUser>/.ssh/`** with `600` permissions and are never exposed in the UI. Configuration files are stored as `/home/<siteUser>/git-config.json` with `600` permissions.

### Update Safety

CloudPanel updates replace the entire `/home/clp/htdocs/app/` directory. The addon handles this with:

1. **Source files** stored separately at `/opt/clp-git-addon/` (untouched by updates)
2. **APT hook** at `/etc/apt/apt.conf.d/99-clp-git-addon` that runs after every `dpkg` operation
3. **Idempotent repair**: The hook checks if patches are intact. If CloudPanel was updated and patches are gone, it re-applies them and clears the Symfony cache

---

## CLI Reference

```
clp-git-addon install     # Full installation
clp-git-addon update      # Download and install the latest release from GitHub
clp-git-addon check       # Verify all components are in place
clp-git-addon repair      # Re-apply patches (runs automatically after updates)
clp-git-addon uninstall   # Remove addon from CloudPanel (keeps site SSH keys/config)
```

---

## Configuration Files

### Per-site configuration

Each site stores its configuration in:

```
/home/<siteUser>/git-config.json
```

Example content:

```json
{
    "filename": "id_ed25519_examplecom",
    "repo_url": "git@github.com:username/example.com.git",
    "branch": "main",
    "deploy_path": "/home/site-www-example/htdocs/example.com",
    "deploy_script_path": "/home/site-www-example/deploy-example.com.sh"
}
```

### SSH keys

Generated keys are stored in:

```
/home/<siteUser>/.ssh/
```

- Private key: `600` permissions
- Public key: `644` permissions

### Deploy scripts

Custom deploy scripts are saved as:

```
/home/<siteUser>/deploy-<domain>.sh
```

The script is made executable and can contain any bash commands needed for your deployment workflow.

---

## Troubleshooting

### Git tab does not appear

```bash
clp-git-addon check    # Identify what is missing
clp-git-addon repair   # Re-apply patches
```

### SSH test fails

1. Verify the public key is registered as a deploy key in your Git repository
2. Ensure the repository URL uses the SSH format: `git@github.com:username/repo.git`
3. Check that the site user can read the private key:
   ```bash
   sudo -u site-www-example cat /home/site-www-example/.ssh/id_ed25519_examplecom.pub
   ```
4. Test manually as the site user:
   ```bash
   sudo -u site-www-example bash -c 'GIT_SSH_COMMAND="ssh -i /home/site-www-example/.ssh/id_ed25519_examplecom" git ls-remote git@github.com:username/repo.git HEAD'
   ```

### Branches are not listed

- Confirm the SSH test succeeds first
- Make sure the repository has at least one branch pushed
- Check the CloudPanel logs for any `git ls-remote` errors

---

## Security

- **No credentials stored centrally**: Per-site configuration and SSH keys live in the site user's home directory
- **SSH private keys**: Stored under `/home/<siteUser>/.ssh/` with `600` permissions -- never accessible via the web
- **Configuration files**: `git-config.json` and deploy scripts are owned by the site user and readable only by that user
- **Authentication**: The Git tab inherits CloudPanel's session-based authentication -- only logged-in CloudPanel users can access it
- **Input validation**: Filenames are sanitized with `preg_replace('/[^a-zA-Z0-9_-]/', '', $filename)` to prevent path traversal
- **Command execution**: The controller uses `shell_exec()` and `sudo` with user-supplied values. The Git tab is intended as a privileged administrator feature.

---

## Background & Motivation

CloudPanel deliberately does not include Git deployment features -- and that is a reasonable design decision to keep the panel lightweight. However, many CloudPanel users need a simple, integrated way to deploy code from private Git repositories without manually managing SSH keys and scripts on the server.

This addon was born out of that exact need: a streamlined Git workflow inside CloudPanel, with per-site SSH keys, configuration persistence, and automatic survival across CloudPanel updates.

---

## Contributing

Contributions are welcome. Please open an issue before submitting large changes.

If you find this addon useful, consider sharing it with other CloudPanel users.

---

## License

MIT License. See [LICENSE](LICENSE) for details.

---

## Credits

Built by [Jefrien](https://github.com/Jefrien).

This Git addon is based on the original [CloudPanel Mail Addon](https://github.com/s-a-s-k-i-a/cloudpanel-mail-addon) by [Saskia Teichmann](https://github.com/s-a-s-k-i-a). The addon structure, installer pattern, and CloudPanel integration approach were adapted from that project.
