#!/bin/bash
# CrowdSky Worker — systemd service setup for Fedora
# Run as: sudo bash setup-service.sh
#
# Assumes:
#   - The repo is already cloned and uv sync has been run
#   - .env is already configured
#   - The current user (who cloned the repo) will run the service

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
WORKER_DIR="$SCRIPT_DIR"
RUN_USER="${SUDO_USER:-$(whoami)}"
VENV_PYTHON="$WORKER_DIR/.venv/bin/python"

echo "=== CrowdSky Worker Service Setup ==="
echo "Worker dir:  $WORKER_DIR"
echo "Run as user: $RUN_USER"
echo "Python:      $VENV_PYTHON"
echo ""

# Check prerequisites
if [ ! -f "$VENV_PYTHON" ]; then
    echo "ERROR: .venv not found. Run 'uv sync' first."
    exit 1
fi
if [ ! -f "$WORKER_DIR/.env" ]; then
    echo "ERROR: .env not found. Copy .env.example to .env and configure it."
    exit 1
fi

# Create tmp directory
mkdir -p "$WORKER_DIR/tmp"
chown "$RUN_USER" "$WORKER_DIR/tmp"

# Write service file with correct paths
cat > /etc/systemd/system/crowdsky-worker.service <<EOF
[Unit]
Description=CrowdSky Stacking Worker
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$RUN_USER
WorkingDirectory=$WORKER_DIR
ExecStart=$VENV_PYTHON -m worker
Restart=always
RestartSec=10
EnvironmentFile=$WORKER_DIR/.env
StandardOutput=journal
StandardError=journal
SyslogIdentifier=crowdsky-worker
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
EOF

# Enable and start
systemctl daemon-reload
systemctl enable crowdsky-worker
systemctl start crowdsky-worker

echo ""
echo "=== Done! ==="
echo ""
systemctl status crowdsky-worker --no-pager
echo ""
echo "Useful commands:"
echo "  sudo systemctl status crowdsky-worker    # check status"
echo "  sudo journalctl -u crowdsky-worker -f    # live logs"
echo "  sudo systemctl restart crowdsky-worker   # restart after code changes"
