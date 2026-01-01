#!/bin/bash

# Change to project directory
cd /home/kiang/public_html/godroad

# Run the crawler
/usr/bin/php crawler.php

# Check if there are any changes in docs/
if [[ -n $(git status docs/ --porcelain) ]]; then
    # Add all changes in docs/
    git add docs/

    # Commit with timestamp
    git commit -m "data: $(date '+%Y-%m-%d %H:%M:%S')"

    # Push to remote
    git push
fi
