#!/bin/bash

# Get project root directory (parent of scripts/)
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_ROOT"

# Run the crawler
/usr/bin/php crawler.php

# Pull latest changes first
git pull --rebase

# Check if there are any changes in docs/
if [[ -n $(git status docs/ --porcelain) ]]; then
    # Add all changes in docs/
    git add docs/

    # Commit with timestamp
    git commit -m "data: $(date '+%Y-%m-%d %H:%M:%S')"

    # Push to remote
    git push
fi
