#!/usr/bin/env bash
# Install or update Scout (https://github.com/kiryano/Scout) into a
# self-contained directory with its own virtualenv.
#
# Usage:
#   bash install.sh            # installs to $SCOUT_HOME (default ~/.scout)
#   SCOUT_HOME=/opt/scout bash install.sh
#
# Safe to re-run: pulls the latest release and reinstalls requirements.
# Scout refuses to start when a newer GitHub release exists, so re-running
# this script is also how you fix an "Update Required" message.
set -euo pipefail

SCOUT_HOME="${SCOUT_HOME:-$HOME/.scout}"
SCOUT_REPO="${SCOUT_REPO:-https://github.com/kiryano/Scout.git}"
PYTHON="${PYTHON:-python3}"

if ! command -v git >/dev/null 2>&1; then
  echo "error: git is required" >&2; exit 1
fi
if ! "$PYTHON" -c 'import sys; sys.exit(0 if sys.version_info >= (3, 10) else 1)' 2>/dev/null; then
  echo "error: Python 3.10+ is required (set PYTHON=/path/to/python3.10+)" >&2; exit 1
fi

if [ -d "$SCOUT_HOME/.git" ]; then
  echo "Updating Scout in $SCOUT_HOME"
  git -C "$SCOUT_HOME" pull --ff-only origin main
else
  echo "Cloning Scout into $SCOUT_HOME"
  git clone --depth 1 "$SCOUT_REPO" "$SCOUT_HOME"
fi

cd "$SCOUT_HOME"

if [ ! -x .venv/bin/python ]; then
  "$PYTHON" -m venv .venv
fi
.venv/bin/python -m pip install --quiet --upgrade pip
.venv/bin/python -m pip install --quiet -r requirements.txt

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created $SCOUT_HOME/.env from .env.example (edit it to add LINKEDIN_COOKIE, proxies, etc.)"
fi

echo
echo "Scout installed: $(.venv/bin/python scout.py --version)"
echo "Run it with:  cd $SCOUT_HOME && .venv/bin/python scout.py"
