# Deployment Guide

A step-by-step guide to deploying CrowdSky on the University of Vienna webspace. Written for someone who hasn't done this before.

## Prerequisites

You need:
- A **univie webspace account** (e.g., `crowdskyo92`) — already set up at https://crowdsky.univie.ac.at/
- Access to the **univie VPN** (for phpMyAdmin)
- The webspace mounted via **SMB** on your machine (for drag-and-drop file deployment)
- The CrowdSky git repo cloned locally

## Step 1: Create the MariaDB Database

1. Go to [Webdatenbank verwalten](https://www.univie.ac.at/ZID/webdatenbank-verwalten/)
2. Log in with your u:account credentials (or webspace account UserID + password)
3. On first login, you'll be asked to **set a database password**
   - This must be different from your u:account password
   - Save it somewhere safe
4. After creation, note down the three values shown:
   - **Datenbankhost** (e.g., `crowdskyo92.mysql.univie.ac.at`)
   - **Datenbankname** (e.g., `crowdskyo92`)
   - **Datenbankuser** (e.g., `crowdskyo92`)

## Step 2: Import the Database Schema

1. Connect to the **univie VPN**
2. Go to [Webdatenbank administrieren (phpMyAdmin)](https://www.univie.ac.at/ZID/webdatenbank-administrieren/)
3. Log in with:
   - **Server:** your Datenbankhost (e.g., `crowdskyo92.mysql.univie.ac.at`)
   - **Username:** your Datenbankuser
   - **Password:** the database password you set in Step 1
4. In phpMyAdmin:
   - Select your database in the left sidebar
   - Click the **Import** tab at the top
   - Click **Choose File** and select `schema.sql` from your local repo
   - Click **Go** at the bottom
5. You should see 5 tables created: `users`, `upload_sessions`, `raw_files`, `stacking_jobs`, `stacked_frames`

Alternative via SSH:
```bash
ssh webspace-access.univie.ac.at
mysql -h crowdskyo92.mysql.univie.ac.at -D crowdskyo92 -u crowdskyo92 -p < schema.sql
```

## Step 3: Create config.php

1. In your local repo, copy `web/config.example.php` to `web/config.php`
2. Fill in the database credentials from Step 1:
   ```php
   define('DB_HOST', 'crowdskyo92.mysql.univie.ac.at');
   define('DB_NAME', 'crowdskyo92');
   define('DB_USER', 'crowdskyo92');
   define('DB_PASS', 'your-database-password');
   ```
3. Generate a random worker API key. Run this on any machine with Python:
   ```
   python -c "import secrets; print(secrets.token_hex(32))"
   ```
   Paste the result into:
   ```php
   define('WORKER_API_KEY', 'paste-the-64-char-hex-string-here');
   ```
4. Update the site URL:
   ```php
   define('SITE_URL', 'https://crowdsky.univie.ac.at');
   ```
5. The u:cloud token is already set in the example. If you create a new share link, update `UCLOUD_SHARE_TOKEN` with the token from the URL (the part after `/s/`).

> **Important:** `config.php` is listed in `.gitignore` and will NOT be committed to git. This is intentional — it contains passwords.

## Step 4: Deploy Files to Webspace

Your webspace is mounted via SMB. The document root is wherever `crowdsky.univie.ac.at` points to.

1. **Delete** the current splash page (e.g., `index.html`) from the document root
2. **Copy everything inside `web/`** from your local repo into the document root:
   ```
   From: D:\Repos\CrowdSky\web\*
   To:   (your SMB-mounted webspace root)\
   ```
   The webspace should now contain:
   ```
   (document root)/
       index.php
       config.php          ← the one you created in Step 3
       config.example.php
       db.php
       auth.php
       upload.php
       finalize.php
       status.php
       stacks.php
       download.php
       fits_utils.php
       webdav.php
       api/
           next_job.php
           job_files.php
           complete_job.php
           fail_job.php
           download_raw.php
           cleanup.php
       templates/
           header.php
           footer.php
       assets/
           css/style.css
   ```
3. **Create an `uploads/` folder** in the document root — this is where raw .fit files will be temporarily stored

## Step 5: Verify the Deployment

1. Visit https://crowdsky.univie.ac.at/
2. You should see the **login/register page** with the dark theme
3. **Register a test account** — fill in username, email, password
4. After registration you'll be redirected to the **upload page**
5. Drag a `.fit` file onto the upload zone to test (it should save to the `uploads/` directory)

### Troubleshooting

| Problem | Likely Cause | Fix |
|---------|-------------|-----|
| White page / 500 error | PHP error, probably DB connection | Check the webspace error log. Verify DB credentials in `config.php` |
| "Access denied" on DB | Wrong password or host | Double-check credentials at Webdatenbank verwalten |
| Upload fails with "Failed to save file" | `uploads/` directory doesn't exist or isn't writable | Create the directory; check permissions |
| CSS not loading / unstyled page | Files not in the right place | Make sure `assets/css/style.css` is at that exact path relative to the document root |

## Step 6: Set Up u:cloud Storage

The u:cloud share is where permanent stacked results are stored.

1. Go to https://ucloud.univie.ac.at/
2. Create a folder for CrowdSky stacks (or use an existing shared folder)
3. Share the folder via **public link** (with password protection if desired)
4. The share URL looks like: `https://ucloud.univie.ac.at/index.php/s/XXXXXXXXX`
5. The token is the `XXXXXXXXX` part — put this in `config.php` as `UCLOUD_SHARE_TOKEN`
6. Create a `crowdsky/stacks` subdirectory inside the share (the worker will create per-user subdirectories automatically)

The WebDAV endpoint for any u:cloud public share is:
```
https://ucloud.univie.ac.at/public.php/webdav
```
Authentication is the share token as username, empty password.

## Step 7: Deploy the Python Worker (Optional for MVP)

The worker is what actually stacks the FITS files. It can run on any machine.

1. On the machine where you want to run the worker:
   ```bash
   cd D:\Repos\CrowdSky
   pip install -r worker/requirements.txt
   ```
   This installs `seestarpy`, `requests`, `python-dotenv`, and `Pillow`.

2. Copy `worker/.env.example` to `worker/.env` and fill in:
   ```
   API_BASE_URL=https://crowdsky.univie.ac.at/api
   WORKER_API_KEY=same-key-as-in-config-php
   UCLOUD_SHARE_TOKEN=ELBci3d9eqyRBHp
   ```

3. Run in daemon mode (continuous polling):
   ```bash
   python -m worker
   ```
   Or process one job and exit (for cron):
   ```bash
   python -m worker --once
   ```

## Step 8: Set Up Cron Cleanup (Recommended)

Abandoned upload sessions should be cleaned up. Add a cron job (or Windows Task Scheduler task) that hits the cleanup endpoint once per hour:

```bash
0 * * * * curl -s "https://crowdsky.univie.ac.at/api/cleanup.php?key=YOUR_WORKER_API_KEY"
```

This deletes local raw files from sessions that have been in 'uploading' state for more than 24 hours.

## Updating the Deployment

When you make code changes:

1. Edit files in the git repo as normal
2. Commit and push to GitHub
3. Copy the changed files from `web/` to the webspace via SMB
4. **Never overwrite `config.php`** on the webspace (it has live credentials and is not in git)

There's no build step — PHP files are interpreted directly.

## Moving to a Different Server

When it's time to migrate away from univie webspace:

1. **Database:** Export via phpMyAdmin (Export tab → SQL format). Import into the new MariaDB/MySQL instance. Update `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` in `config.php`.
2. **Files:** Copy the document root to the new server. Create `config.php` with new credentials.
3. **u:cloud:** If changing storage, update `UCLOUD_WEBDAV_URL` and `UCLOUD_SHARE_TOKEN`. If moving to S3 or similar, you'll need to rewrite `webdav.php` and the worker's `webdav.py`.
4. **Worker:** Update `API_BASE_URL` in `.env` to point to the new server. The worker doesn't care where it runs — just needs HTTP access.
5. **DNS:** Point the domain to the new server.
