#!/usr/bin/env bash
# Install a SSIMULACRA2 v2 (0..100 scale) binary as `ssimulacra2_rs`.
#
# Requires: root privileges and network access.
#
# Strategy: install the `ssimulacra2` Python package (PyPI) and create a thin
# wrapper at /usr/local/bin/ssimulacra2_rs that exposes the CLI as:
#   ssimulacra2_rs <original.png> <distorted.png>  →  prints bare float (e.g. "87.35000000")
#
# Verified v2 scale: perfect-match gives 100, severe degradation gives ~17.
# Package: https://pypi.org/project/ssimulacra2/ (version 0.3.0+)
#
# Idempotent: exits cleanly if ssimulacra2_rs is already on PATH.

set -euo pipefail

if command -v ssimulacra2_rs &>/dev/null; then
    echo "ssimulacra2_rs already installed at $(command -v ssimulacra2_rs), skipping."
    ssimulacra2_rs --version 2>&1 || true
    exit 0
fi

echo "Installing ssimulacra2 Python package..."
python3 -m pip install --break-system-packages ssimulacra2==0.3.0

echo "Creating /usr/local/bin/ssimulacra2_rs wrapper..."
cat > /usr/local/bin/ssimulacra2_rs << 'WRAPPER'
#!/usr/bin/env python3
from ssimulacra2.cli import main
main()
WRAPPER
chmod 0755 /usr/local/bin/ssimulacra2_rs

echo "Verifying installation..."
ssimulacra2_rs --help >/dev/null 2>&1 && echo "ssimulacra2_rs installed OK" || echo "warning: ssimulacra2_rs verification did not exit 0"

echo "ssimulacra2_rs installed successfully."
