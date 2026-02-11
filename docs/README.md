# CrowdSky Documentation

## What is CrowdSky?

CrowdSky is a cloud stacking service for Seestar S50 telescope users. Users upload raw FITS files, and CrowdSky automatically stacks them into 15-minute cadence frames — useful for time-domain astronomy. The service is free at 15-minute resolution; finer cadence (3-min, 1-min) and raw preservation are planned as paid tiers.

## Documentation Index

| Document | Description |
|----------|-------------|
| [Architecture](architecture.md) | System overview, data flow, design decisions |
| [Deployment Guide](deployment.md) | Step-by-step guide to deploying on univie webspace |
| [Database Schema](database.md) | All tables, columns, relationships, and indexes |
| [PHP Code Reference](php-reference.md) | Every PHP file explained: what it does, how it works |
| [Worker API Reference](api-reference.md) | HTTP endpoints the Python worker calls |
| [Python Worker Reference](worker-reference.md) | How the stacking worker works |
| [Configuration Reference](configuration.md) | Every config value explained |

## Quick Links

- **Live site:** https://crowdsky.univie.ac.at/
- **u:cloud storage:** https://ucloud.univie.ac.at/index.php/s/ELBci3d9eqyRBHp
- **GitHub repo:** https://github.com/astronomyk/CrowdSky

## Tech Stack

- **Frontend:** Plain PHP (no framework), HTML/CSS/JS
- **Database:** MariaDB on univie webspace
- **File storage:** u:cloud (Nextcloud/WebDAV, 250 GB) for permanent stacks; webspace local disk (50 GB) for temporary raw uploads
- **Worker:** Python, using the `seestarpy` package for stacking
- **Authentication:** bcrypt passwords, CSRF tokens, Bearer tokens for the worker API
