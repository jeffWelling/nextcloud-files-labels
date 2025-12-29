#!/bin/bash
# SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
# SPDX-License-Identifier: AGPL-3.0-or-later

# Download the official AGPL-3.0 license text

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
LICENSE_FILE="$PROJECT_ROOT/LICENSE"

echo "Downloading AGPL-3.0 license..."
curl -fsSL https://www.gnu.org/licenses/agpl-3.0.txt -o "$LICENSE_FILE"

echo "License file created at: $LICENSE_FILE"
echo "First 5 lines:"
head -5 "$LICENSE_FILE"
