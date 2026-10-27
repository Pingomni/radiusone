#!/usr/bin/env bash
# Before 2.3 this set up the root helpers and sudoers; ops/radiusone-postinstall does that now. Kept because the
# updater from before 2.3 (frweb-update) runs it after installing a release. As root:
#   sudo bash <app>/ops/apply-service-control.sh
exec bash "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/radiusone-postinstall" "$@"
