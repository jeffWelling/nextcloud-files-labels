#!/bin/sh
# SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Pre-installation hook for the Nextcloud apache image on GHA's overlay2
# storage. Two perm gotchas this fixes:
#
#   1. /var/www/html/custom_apps is created by Docker as root:root because
#      the :ro bind mount of files_labels inside it makes Docker re-create
#      the parent. www-data can't write to it. Nextcloud's apps_paths
#      includes custom_apps as a writable target, so install fails.
#
#   2. The previous version of this hook ran chown -R on custom_apps,
#      which then tried to chown into the :ro bind mount and printed
#      "Read-only file system" errors. We now chown custom_apps
#      non-recursively (the :ro contents don't matter for is_writable
#      on the parent).

set -u

dump() {
    label="$1"
    echo "=== files_labels hook: $label ==="
    ls -ld /var/www/html /var/www/html/apps /var/www/html/custom_apps \
           /var/www/html/config /var/www/html/data 2>&1 | head -10
    echo "--- write test as www-data (via runuser) ---"
    for d in apps custom_apps config data; do
        runuser -u www-data -- sh -c \
            "test -w /var/www/html/$d && echo '$d=writable' || echo '$d=READONLY'"
    done
}

dump "BEFORE"

# Recurse on apps/config/data — no read-only sub-mounts there.
for dir in apps config data; do
    if [ -d "/var/www/html/$dir" ]; then
        chown -R www-data:www-data "/var/www/html/$dir"
        chmod -R u+rwX "/var/www/html/$dir"
    fi
done

# Non-recursive on custom_apps — files_labels under it is :ro and would
# print harmless but noisy "Read-only file system" errors. Only the
# parent's perms matter for is_writable().
if [ -d /var/www/html/custom_apps ]; then
    chown www-data:www-data /var/www/html/custom_apps
    chmod 0755 /var/www/html/custom_apps
fi

dump "AFTER"

echo "files_labels hook: done"
