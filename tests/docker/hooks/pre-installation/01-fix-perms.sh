#!/bin/sh
# SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The Nextcloud apache entrypoint sometimes leaves /var/www/html/apps not
# writable by www-data on GHA's overlay2 storage driver, causing
# `occ maintenance:install` to abort with "Cannot write into 'apps' directory".
# This hook runs after the populate step and before the install, and re-chowns
# the directories the install needs to touch. Bind-mounted paths
# (custom_apps/files_labels) are skipped so a read-only mount doesn't fail us.

set -eu

for dir in apps config data custom_apps; do
    if [ -d "/var/www/html/$dir" ]; then
        # `|| true` because custom_apps may contain a :ro bind mount that we
        # can't (and don't need to) chown.
        chown -R www-data:www-data "/var/www/html/$dir" 2>/dev/null || true
    fi
done

echo "files_labels test hook: re-chowned /var/www/html/{apps,config,data,custom_apps}"
