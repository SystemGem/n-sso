#!/usr/bin/env bash
# Builds dist/nplus-sso.zip, ready for Plugins > Add New > Upload Plugin.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION=$(grep -m1 "Version:" "$ROOT/nplus-sso.php" | awk '{print $NF}')
BUILD="$(mktemp -d)"
mkdir -p "$BUILD/nplus-sso" "$ROOT/dist"
cp -r "$ROOT/nplus-sso.php" "$ROOT/uninstall.php" "$ROOT/readme.txt" "$ROOT/includes" "$ROOT/languages" "$BUILD/nplus-sso/"
rm -f "$ROOT/dist/nplus-sso.zip"
(cd "$BUILD" && zip -qr "$ROOT/dist/nplus-sso.zip" nplus-sso)
rm -rf "$BUILD"
echo "Built dist/nplus-sso.zip (v$VERSION)"
