#!/usr/bin/env bash
# Moves the server, MariaDB, FreeRADIUS and the portal to another time zone (default Europe/Tirane) and
# converts the times already stored. The same change Settings → Regional → Time zone starts; see
# ops/frweb-set-timezone for what it does. Run as root:
#   sudo bash /opt/freeradius-web-laravel/ops/apply-timezone.sh [Zone/Name]
set -euo pipefail
exec bash /opt/freeradius-web-laravel/ops/frweb-set-timezone apply "${1:-Europe/Tirane}"
