"""Shared GPS loading and segmentation logic.

Both build_summary.py and calculate_coverage.py use this module so the
same records produce the same segments.
"""

import json
import glob
import os
import math
from datetime import datetime

MAX_TIME_GAP = 300        # seconds — split into new segment above this
MAX_CYCLING_SPEED = 50    # km/h — above this is likely not cycling
MIN_MOVING_SPEED = 2      # km/h — below this is likely resting
REST_DISTANCE_THRESHOLD = 50   # meters
MIN_MOVEMENT_THRESHOLD = 10    # meters — merge stationary points below this
MIN_INDEX_DISTANCE = 1000      # meters — exclude dates below this from index


def haversine(lat1, lng1, lat2, lng2):
    R = 6371000
    dlat = math.radians(lat2 - lat1)
    dlng = math.radians(lng2 - lng1)
    a = (math.sin(dlat / 2) ** 2 +
         math.cos(math.radians(lat1)) * math.cos(math.radians(lat2)) *
         math.sin(dlng / 2) ** 2)
    return R * 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a))


def load_day_records(day_dir):
    """Load raw records from a single date directory.

    Returns list of dicts with keys: timestamp (str HHMMSS), time (str HH:MM:SS),
    ts (datetime), data (original entry dict), lat, lng.
    Sorted by filename (timestamp).
    """
    records = []
    for json_file in sorted(glob.glob(os.path.join(day_dir, "*.json"))):
        try:
            with open(json_file) as f:
                entries = json.load(f)
        except (json.JSONDecodeError, OSError):
            continue
        for entry in entries:
            gps_points = entry.get("GPS", [])
            gps_time = entry.get("gps_time")
            if not gps_time or not gps_points:
                continue
            p = gps_points[0]
            if not p.get("lat") or not p.get("lng"):
                continue
            try:
                ts = datetime.strptime(gps_time, "%Y-%m-%d %H:%M:%S")
            except ValueError:
                continue
            timestamp = os.path.basename(json_file).replace(".json", "")
            time_str = f"{timestamp[0:2]}:{timestamp[2:4]}:{timestamp[4:6]}"
            records.append({
                "timestamp": timestamp,
                "time": time_str,
                "ts": ts,
                "data": entry,
                "lat": p["lat"],
                "lng": p["lng"],
            })
    records.sort(key=lambda r: r["ts"])
    return records


def split_segments(records, max_gap=MAX_TIME_GAP):
    """Split records into continuous segments by time gap.

    Returns list of segments, each a list of record dicts.
    Single-point segments are dropped.
    """
    if len(records) < 2:
        return []
    segments = []
    current = [records[0]]
    for i in range(1, len(records)):
        gap = (records[i]["ts"] - records[i - 1]["ts"]).total_seconds()
        if gap <= max_gap:
            current.append(records[i])
        else:
            if len(current) >= 2:
                segments.append(current)
            current = [records[i]]
    if len(current) >= 2:
        segments.append(current)
    return segments


def merge_stationary(records, threshold=MIN_MOVEMENT_THRESHOLD):
    """Merge consecutive points with minimal movement.

    Keeps the first point of each stationary period with duration info.
    """
    if len(records) < 2:
        return records
    merged = [records[0]]
    stationary_start = None
    stationary_count = 0
    for i in range(1, len(records)):
        last = merged[-1]
        cur = records[i]
        dist = haversine(last["lat"], last["lng"], cur["lat"], cur["lng"])
        if dist < threshold:
            if stationary_start is None:
                stationary_start = len(merged) - 1
            stationary_count += 1
            merged[stationary_start]["stationaryUntil"] = cur["time"]
            merged[stationary_start]["stationaryDuration"] = stationary_count
        else:
            stationary_start = None
            stationary_count = 0
            merged.append(cur)
    return merged


def load_all_paths(paths_glob):
    """Load GPS paths for coverage calculation.

    Returns (all_paths, all_timestamps) where all_paths is a list of
    (coords, date_label) tuples — one per continuous segment.
    """
    all_paths = []
    all_timestamps = []
    for date_dir in sorted(glob.glob(paths_glob)):
        if not os.path.isdir(date_dir):
            continue
        date_label = os.path.basename(date_dir)
        records = load_day_records(date_dir)
        for rec in records:
            all_timestamps.append(rec["ts"])
        segments = split_segments(records)
        for seg in segments:
            coords = [(r["lng"], r["lat"]) for r in seg]
            all_paths.append((coords, date_label))
    return all_paths, all_timestamps
