#!/usr/bin/env bash
# Copy the plugin to a site over SSH.
# Usage: ./deploy.sh <ssh-host> <path/to/wp-content/plugins/mjs-ai-ready>
set -euo pipefail
target_host="${1:?ssh host, e.g. mjswebsites}"
target_path="${2:?plugin folder on the server, e.g. public_html/wp-content/plugins/mjs-ai-ready}"
cd "$(dirname "$0")"
for f in mjs-ai-ready.php inc/*.php; do php -l "$f" >/dev/null; done
rsync -az --delete \
	--exclude '.git' --exclude '.github' --exclude '.gitignore' --exclude '.DS_Store' \
	--exclude 'README.md' --exclude 'deploy.sh' --exclude 'bin' --exclude 'report' --exclude 'reports' \
	--exclude 'examples' --exclude 'sites' --exclude 'dist' \
	-e "ssh -o ControlMaster=auto -o ControlPath=$HOME/.ssh/cm-%C -o ControlPersist=10m" \
	./ "$target_host:$target_path/"
echo "Deployed to $target_host:$target_path"
echo "Next: ssh $target_host 'cd <wp-path> && wp plugin activate mjs-ai-ready && wp ai-ready status'"
