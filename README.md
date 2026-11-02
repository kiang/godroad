# godroad

GPS data crawler for [godroad.tw](https://gps.godroad.tw), tracking cycling routes and generating daily summaries.

## Overview

This project periodically fetches GPS tracking data from the Godroad GPS server, stores snapshots as JSON files organized by date, and builds summary data with route analysis (distance, speed, rest stops, segments).

The frontend visualization is hosted at [tainan.olc.tw/p/fififans](https://tainan.olc.tw/p/fififans).

## Requirements

- PHP >= 7.4
- Composer

## Setup

```sh
composer install
```

## Usage

### Crawl GPS data

```sh
php crawler.php
```

Fetches the latest GPS data from the server. Only saves a new snapshot if the data has changed since the last fetch. Data is stored in `docs/YYYY-MM-DD/HHMMSS.json`.

### Build summaries

```sh
# Build summary for today
php scripts/build_summary.php

# Build summary for a specific date
php scripts/build_summary.php 20260112

# Rebuild all dates
php scripts/build_summary.php --all

# Rebuild index only
php scripts/build_summary.php --index
```

Processes raw GPS snapshots into summary JSON files with route analysis including:
- Total distance and per-segment distances
- Speed calculations and anomaly detection
- Rest stop identification
- Stationary point merging
- Continuous segment filtering (removes gaps > 5 minutes)

### Automated collection (cron)

```sh
# Run via cron script (crawls, commits, and pushes changes)
bash scripts/cron.sh
```

## Project Structure

```
├── crawler.php              # GPS data fetcher
├── scripts/
│   ├── build_summary.php    # Route summary generator
│   └── cron.sh              # Automated crawl + git commit
└── docs/                    # Raw GPS data (by date)
    └── YYYY-MM-DD/
        └── HHMMSS.json
```
