#!/bin/sh
# SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Diagnostic + perm-fixing pre-installation hook for the Nextcloud
# apache image on GHA's overlay2 storage. The install repeatedly fails
# with "Cannot write into 'apps' directory" even after a chown -R; this
# script logs perm state before and after the fix so we can see what's
# actually happening.

set -u

dump() {
    label="$1"
    echo "=== files_labels hook: $label ==="
    echo "--- /var/www/html (top level) ---"
    ls -la /var/www/html/ | head -20 || true
    echo "--- /var/www/html/apps (first 5 entries) ---"
    ls -la /var/www/html/apps 2>&1 | head -8 || true
    echo "--- stat /var/www/html/apps ---"
    stat /var/www/html/apps 2>&1 | head -5 || true
    echo "--- write test as www-data ---"
    su -s /bin/sh www-data -c \
        'test -w /var/www/html/apps && echo "writable=YES" || echo "writable=NO"' \
        2>&1 || echo "su failed: $?"
    su -s /bin/sh www-data -c \
        'touch /var/www/html/apps/.fixperm-test 2>&1 && rm /var/www/html/apps/.fixperm-test 2>&1 && echo "touch=OK" || echo "touch=FAIL"' \
        2>&1 || true
}

dump "BEFORE"

for dir in apps config data custom_apps; do
    if [ -d "/var/www/html/$dir" ]; then
        chown -R www-data:www-data "/var/www/html/$dir" 2>&1 \
            | head -5 || true
        chmod -R u+rwX "/var/www/html/$dir" 2>&1 \
            | head -5 || true
    fi
done

dump "AFTER"

echo "files_labels hook: done"
