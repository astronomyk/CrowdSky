# FAQ / Operations Guide

Quick reference for managing CrowdSky. Written for future-you who has forgotten everything.

---

## Worker on Zeus

### How do I check if the worker is running?

```bash
ssh zeus.astro.univie.ac.at
sudo systemctl status crowdsky-worker
```

You want to see `Active: active (running)`.

### How do I see what the worker is doing right now?

```bash
sudo journalctl -u crowdsky-worker -f
```

This shows live logs. You'll see lines like:
- `No pending jobs.` — idle, polling every 30 seconds
- `Claimed job 42: M42 chunk=20250115.78_83.6_+22.0` — picked up a job
- `Job 42: completed (10/10 aligned)` — finished stacking

Press `Ctrl+C` to stop watching.

### How do I see recent logs?

```bash
# Last 50 lines
sudo journalctl -u crowdsky-worker -n 50 --no-pager

# Last hour
sudo journalctl -u crowdsky-worker --since "1 hour ago"

# Today only
sudo journalctl -u crowdsky-worker --since today
```

### How do I stop the worker?

```bash
sudo systemctl stop crowdsky-worker
```

It will stay stopped until you start it again (or the server reboots — it's set to auto-start on boot).

### How do I start the worker?

```bash
sudo systemctl start crowdsky-worker
```

### How do I restart the worker?

```bash
sudo systemctl restart crowdsky-worker
```

Do this after updating the code or `.env`.

### How do I update the worker code on zeus?

```bash
ssh zeus.astro.univie.ac.at
cd /opt/crowdsky/CrowdSky
sudo git pull
sudo systemctl restart crowdsky-worker
```

If dependencies changed (new packages in `pyproject.toml`):

```bash
cd /opt/crowdsky/CrowdSky/worker
sudo -u crowdsky ~/.local/bin/uv sync   # or: sudo /home/crowdsky/.local/bin/uv sync
sudo systemctl restart crowdsky-worker
```

### How do I disable auto-start on boot?

```bash
sudo systemctl disable crowdsky-worker
```

Re-enable with:
```bash
sudo systemctl enable crowdsky-worker
```

---

## PHP Webspace

### Where is the webspace?

The PHP app is served from the univie webspace at https://crowdsky.univie.ac.at/. Files are deployed via SMB mount on your Windows machine.

### How do I update the PHP code?

1. Edit files in `D:\Repos\CrowdSky\web\` on your laptop
2. Copy the changed files to the SMB-mounted webspace
3. That's it — PHP files are interpreted on every request, no restart needed

**Never overwrite `config.php` on the webspace** — it has live credentials that aren't in git.

### Where is the database?

MariaDB hosted by univie:
- **Host:** `crowdskyo92.mysql.univie.ac.at`
- **Database:** `crowdskyo92`
- **User:** `crowdskyo92`
- **Admin panel:** phpMyAdmin via VPN at the "Webdatenbank administrieren" link

### How do I clean up abandoned uploads?

Hit this URL (or set it up as a cron job):

```
https://crowdsky.univie.ac.at/api/cleanup.php?key=52e4cb09f61d9ef65c9837645a7e4dd46ccee7a8be454acac2aeb2d08c186bc8
```

This deletes local raw files from sessions stuck in 'uploading' for more than 24 hours.

---

## u:cloud Storage

### Where are the stacked files?

On u:cloud at https://ucloud.univie.ac.at/index.php/s/ELBci3d9eqyRBHp

Directory structure:
```
/crowdsky/
    stacks/
        user_1/
            NGC_188/
                stack_20251024.75_10.3_+85.1_1.fits
                stack_20251024.75_10.3_+85.1_1_thumb.png
            M42/
                ...
```

### How does WebDAV authentication work?

The share token from the URL (`ELBci3d9eqyRBHp`) is used as the username, with an empty password. The WebDAV endpoint is always:

```
https://ucloud.univie.ac.at/public.php/webdav
```

### What if I create a new u:cloud share?

Update the token in two places:
1. `web/config.php` on the webspace — `UCLOUD_SHARE_TOKEN`
2. `worker/.env` on zeus — `UCLOUD_SHARE_TOKEN`

Then restart the worker: `sudo systemctl restart crowdsky-worker`

---

## Common Scenarios

### "I uploaded files but nothing is happening"

1. Did you click **Finalize**? Uploads don't create stacking jobs until finalized.
2. Is the worker running? Check with `sudo systemctl status crowdsky-worker` on zeus.
3. Check the jobs page at https://crowdsky.univie.ac.at/status.php — are jobs in `pending`?

### "A job is stuck in processing"

The worker probably crashed while processing it. It won't be retried automatically because it's stuck in `processing` state. Options:

1. Check logs: `sudo journalctl -u crowdsky-worker --since "1 hour ago"`
2. Manually reset via phpMyAdmin: `UPDATE stacking_jobs SET status='pending', worker_id=NULL WHERE id=X`
3. Restart the worker: `sudo systemctl restart crowdsky-worker`

### "The worker keeps crashing"

Check logs for the error:
```bash
sudo journalctl -u crowdsky-worker -n 100 --no-pager
```

Common causes:
- **Network issue:** can't reach `crowdsky.univie.ac.at` or `ucloud.univie.ac.at`
- **Disk full:** `/tmp/crowdsky-worker` ran out of space
- **seestarpy bug:** bad FITS data causing an unhandled exception

The service auto-restarts every 10 seconds, so transient network issues usually resolve themselves.

### "I changed the worker API key"

Update it in both places:
1. `web/config.php` on the webspace
2. `worker/.env` on zeus (`/opt/crowdsky/CrowdSky/worker/.env`)

Then: `sudo systemctl restart crowdsky-worker`

### "I want to run a second worker"

The system supports multiple workers. Just set up another machine with:
- A different `WORKER_ID` in `.env`
- The same `WORKER_API_KEY` and `UCLOUD_SHARE_TOKEN`

The `FOR UPDATE SKIP LOCKED` in `next_job.php` ensures no two workers grab the same job.

### "I need to wipe everything and start fresh"

1. **Database:** In phpMyAdmin, drop all tables, then re-import `schema.sql`
2. **Webspace uploads:** Delete everything in the `uploads/` directory
3. **u:cloud stacks:** Delete the contents of `/crowdsky/stacks/`
4. **Worker:** `sudo systemctl restart crowdsky-worker` (no state to clear)

---

## Key Files and Where They Live

| What | Where | Sensitive? |
|------|-------|-----------|
| PHP code | univie webspace (SMB mount) | No |
| PHP config | `config.php` on webspace | **Yes** — DB password, API key |
| Database | `crowdskyo92.mysql.univie.ac.at` | Via VPN only |
| Worker code | `/opt/crowdsky/CrowdSky/worker/` on zeus | No |
| Worker config | `/opt/crowdsky/CrowdSky/worker/.env` on zeus | **Yes** — API key, u:cloud token |
| Worker venv | `/opt/crowdsky/CrowdSky/worker/.venv/` on zeus | No |
| Worker temp files | `/tmp/crowdsky-worker/` on zeus | No (auto-cleaned) |
| Stacked FITS | u:cloud (250 GB) | No |
| Raw uploads | webspace `uploads/` dir (50 GB) | No (temporary) |
| Service file | `/etc/systemd/system/crowdsky-worker.service` on zeus | No |
| Git repo | https://github.com/astronomyk/CrowdSky (private) | Token in `upload.py` |
