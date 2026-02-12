# Server Deployment — zeus.astro.univie.ac.at

The CrowdSky stacking worker runs as a systemd service on **zeus**, a Fedora 39 server in the office at the University of Vienna (32 cores, 256 GB RAM).

## Current Setup

| Item | Value |
|------|-------|
| Server | `zeus.astro.univie.ac.at` |
| OS | Fedora 39 |
| Repo location | `/opt/crowdsky/CrowdSky` |
| Worker directory | `/opt/crowdsky/CrowdSky/worker` |
| Virtual environment | `/opt/crowdsky/CrowdSky/worker/.venv` |
| Temp work directory | `/tmp/crowdsky-worker` |
| Service name | `crowdsky-worker` |
| Service runs as user | `athena` |
| Repo cloned as user | `crowdsky` (system account, no login shell) |
| Python version | CPython 3.12.2 |

## How It Got Set Up

### 1. System user created

```bash
sudo useradd -r -s /sbin/nologin -m -d /opt/crowdsky crowdsky
```

This created the `crowdsky` system account with home at `/opt/crowdsky`. It has no login shell — it's only used to own the files.

### 2. Repo cloned and dependencies installed

```bash
sudo -u crowdsky bash
cd /opt/crowdsky
git clone https://github.com/astronomyk/CrowdSky.git
cd CrowdSky/worker
curl -LsSf https://astral.sh/uv/install.sh | sh
~/.local/bin/uv sync
exit
```

`uv sync` reads `worker/pyproject.toml`, creates a `.venv`, and installs all dependencies including `seestarpy` (pulled directly from GitHub).

### 3. Configuration

The `.env` file was created at `/opt/crowdsky/CrowdSky/worker/.env`:

```ini
API_BASE_URL=https://crowdsky.univie.ac.at/api
WORKER_API_KEY=52e4cb09f61d9ef65c9837645a7e4dd46ccee7a8be454acac2aeb2d08c186bc8
UCLOUD_WEBDAV_URL=https://ucloud.univie.ac.at/public.php/webdav
UCLOUD_SHARE_TOKEN=ELBci3d9eqyRBHp
UCLOUD_BASE_PATH=/crowdsky
WORKER_ID=office-server
POLL_INTERVAL=30
WORK_DIR=/tmp/crowdsky-worker
```

**Important:** `WORK_DIR` must be an absolute path that the service user (`athena`) can write to. Using `./tmp` fails because the repo root is owned by `crowdsky`. `/tmp/crowdsky-worker` was created with `athena` as owner.

### 4. Permissions fixed

The repo is owned by `crowdsky` but the service runs as `athena`, so the repo directories need to be world-readable:

```bash
sudo chmod 755 /opt/crowdsky /opt/crowdsky/CrowdSky /opt/crowdsky/CrowdSky/worker
```

Git also needed to be told the repo is safe for other users:

```bash
sudo git config --global --add safe.directory /opt/crowdsky/CrowdSky
```

### 5. Systemd service installed

```bash
sudo bash /opt/crowdsky/CrowdSky/worker/setup-service.sh
```

This script (`worker/setup-service.sh`) auto-detects the paths and user, writes the service file to `/etc/systemd/system/crowdsky-worker.service`, and starts the service.

The generated service file:

```ini
[Unit]
Description=CrowdSky Stacking Worker
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=athena
WorkingDirectory=/opt/crowdsky/CrowdSky
ExecStart=/opt/crowdsky/CrowdSky/worker/.venv/bin/python -m worker
Restart=always
RestartSec=10
EnvironmentFile=/opt/crowdsky/CrowdSky/worker/.env
StandardOutput=journal
StandardError=journal
SyslogIdentifier=crowdsky-worker
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
```

Key points:
- `WorkingDirectory` is the **repo root** (not `worker/`), because `python -m worker` needs to find the `worker` package
- `Restart=always` with `RestartSec=10` means it auto-restarts on crashes
- Logs go to systemd journal (viewable with `journalctl`)

## Gotchas We Hit During Setup

| Problem | Cause | Fix |
|---------|-------|-----|
| `status=200/CHDIR` | `athena` couldn't enter `/opt/crowdsky/` | `chmod 755` on the directory chain |
| `No module named worker` | `WorkingDirectory` was `worker/` instead of repo root | Changed to parent directory |
| `Permission denied: 'tmp'` | `WORK_DIR=./tmp` resolved to repo root, owned by `crowdsky` | Changed to `/tmp/crowdsky-worker` |
| `dubious ownership in repository` | Git repo owned by `crowdsky`, commands run as `athena` | `git config --global --add safe.directory` |
| `.env not found` | `.env` was created by `crowdsky` user but `setup-service.sh` ran from `athena`'s context | Created `.env` with `sudo` |
