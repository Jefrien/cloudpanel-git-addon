#!/bin/bash
set -e

if [ -z "${1:-}" ]; then
    echo "Usage: $0 <new-version>"
    echo "Example: $0 1.0.2"
    exit 1
fi

NEW_VERSION="$1"
ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "$NEW_VERSION" > "$ROOT_DIR/VERSION"

# Update README release install command
sed -i.bak -E "s|/refs/tags/v[0-9]+\.[0-9]+\.[0-9]+\.tar\.gz|/refs/tags/v${NEW_VERSION}.tar.gz|g" "$ROOT_DIR/README.md"
sed -i.bak -E "s|/opt/cloudpanel-git-addon-[0-9]+\.[0-9]+\.[0-9]+ /opt/clp-git-addon|/opt/cloudpanel-git-addon-${NEW_VERSION} /opt/clp-git-addon|g" "$ROOT_DIR/README.md"
rm -f "$ROOT_DIR/README.md.bak"

echo "Version bumped to $NEW_VERSION"
echo ""
echo "Next steps:"
echo "  git add VERSION README.md"
echo "  git commit -m \"Bump version to $NEW_VERSION\""
echo "  git tag -a v$NEW_VERSION -m \"Release v$NEW_VERSION\""
echo "  git push origin v$NEW_VERSION"
