#!/usr/bin/env python3
"""
Build summary JSON data from daily GPS JSON records.

Replaces build_summary.php — uses all continuous segments instead of
only the longest one, so the output reflects the same data as
calculate_coverage.py.

Usage: python3 scripts/build_summary.py
"""

import sys
import os
import json
import math
from datetime import datetime

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from gps_loader import (
    load_day_records, split_segments, merge_stationary, haversine,
    MAX_CYCLING_SPEED, MIN_MOVING_SPEED, REST_DISTANCE_THRESHOLD,
    MIN_INDEX_DISTANCE,
)

DOCS_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "docs")
OUTPUT_DIR = "/home/kiang/public_html/tainan.olc.tw/docs/p/fififans/data"


def get_available_dates():
    dirs = sorted(
        d for d in os.listdir(DOCS_DIR)
        if os.path.isdir(os.path.join(DOCS_DIR, d)) and len(d) == 10 and d[4] == "-"
    )
    return dirs


def build(date_formatted):
    source_dir = os.path.join(DOCS_DIR, date_formatted)
    if not os.path.isdir(source_dir):
        print(f"Skipping {date_formatted}: Directory not found")
        return

    records = load_day_records(source_dir)
    if not records:
        print(f"Skipping {date_formatted}: No records found")
        return

    segments = split_segments(records)
    if not segments:
        print(f"Skipping {date_formatted}: No continuous segments found")
        return

    # Merge stationary points within each segment, then combine
    all_records = []
    for seg in segments:
        all_records.extend(merge_stationary(seg))

    summary = generate_summary(date_formatted, all_records)
    if not summary:
        return

    os.makedirs(OUTPUT_DIR, exist_ok=True)
    output_file = os.path.join(OUTPUT_DIR, f"{date_formatted}.json")
    with open(output_file, "w", encoding="utf-8") as f:
        json.dump(summary, f, ensure_ascii=False, indent=2)

    print(f"Generated: {output_file}")
    print(f"  Total records: {len(all_records)}, segments: {len(segments)}, "
          f"distance: {summary['totalDistanceKm']} km")


def generate_summary(date_formatted, records):
    if not records:
        return None

    project_name = records[0]["data"].get("pjName", "GPS Tracking")
    dot_name = records[0]["data"].get("dot_name", "")

    all_points = []
    timeline = []
    total_distance = 0.0
    rest_stops = []
    speed_anomalies = []

    prev = None
    is_resting = False
    current_rest_start = None

    for idx, rec in enumerate(records):
        distance = 0.0
        speed = 0.0
        status = "normal"

        if prev is not None:
            distance = haversine(prev["lat"], prev["lng"], rec["lat"], rec["lng"])
            time_diff = (rec["ts"] - prev["ts"]).total_seconds()

            if time_diff > 0:
                speed = (distance / time_diff) * 3.6

            total_distance += distance

            if speed > MAX_CYCLING_SPEED:
                status = "speed_anomaly"
                speed_anomalies.append({
                    "index": idx,
                    "time": rec["time"],
                    "speed": round(speed, 1),
                    "lat": rec["lat"],
                    "lng": rec["lng"],
                })
                if is_resting:
                    is_resting = False
                    current_rest_start = None
            elif distance < REST_DISTANCE_THRESHOLD:
                status = "rest"
                if not is_resting:
                    is_resting = True
                    current_rest_start = {
                        "index": idx,
                        "pointIndex": len(all_points),
                        "time": rec["time"],
                        "lat": rec["lat"],
                        "lng": rec["lng"],
                        "addr": rec["data"].get("addr", ""),
                        "cumulativeDistance": total_distance,
                    }
                    rest_stops.append(current_rest_start)
            else:
                if is_resting:
                    is_resting = False
                    current_rest_start = None

        all_points.append({"lat": rec["lat"], "lng": rec["lng"]})

        timeline.append({
            "time": rec["time"],
            "lat": rec["lat"],
            "lng": rec["lng"],
            "addr": rec["data"].get("addr", ""),
            "power": rec["data"].get("dot_Power", ""),
            "gpsType": rec["data"].get("gpsType", ""),
            "distance": round(distance),
            "speed": round(speed, 1),
            "status": status,
            "stationaryUntil": rec.get("stationaryUntil", ""),
            "stationaryDuration": rec.get("stationaryDuration", 0),
        })

        prev = rec

    if not all_points:
        return None

    center_lat = sum(p["lat"] for p in all_points) / len(all_points)
    center_lng = sum(p["lng"] for p in all_points) / len(all_points)

    # Build segments between start, rest stops, and end
    segments = []
    prev_distance = 0.0
    prev_name = "起點"
    prev_time = timeline[0]["time"]
    prev_addr = timeline[0]["addr"]
    prev_point_idx = 0

    for i, stop in enumerate(rest_stops):
        seg_dist = (stop["cumulativeDistance"] - prev_distance) / 1000
        segments.append({
            "from": prev_name,
            "fromTime": prev_time,
            "fromAddr": prev_addr,
            "fromPointIndex": prev_point_idx,
            "to": f"休息 {i + 1}",
            "toTime": stop["time"],
            "toAddr": stop["addr"],
            "toPointIndex": stop["pointIndex"],
            "distance": round(seg_dist, 2),
        })
        prev_distance = stop["cumulativeDistance"]
        prev_name = f"休息 {i + 1}"
        prev_time = stop["time"]
        prev_addr = stop["addr"]
        prev_point_idx = stop["pointIndex"]

    final_dist = (total_distance - prev_distance) / 1000
    segments.append({
        "from": prev_name,
        "fromTime": prev_time,
        "fromAddr": prev_addr,
        "fromPointIndex": prev_point_idx,
        "to": "終點",
        "toTime": timeline[-1]["time"],
        "toAddr": timeline[-1]["addr"],
        "toPointIndex": len(all_points) - 1,
        "distance": round(final_dist, 2),
    })

    return {
        "date": date_formatted,
        "projectName": project_name,
        "dotName": dot_name,
        "totalDistance": round(total_distance),
        "totalDistanceKm": round(total_distance / 1000, 2),
        "restCount": len(rest_stops),
        "anomalyCount": len(speed_anomalies),
        "center": {"lat": center_lat, "lng": center_lng},
        "points": all_points,
        "timeline": timeline,
        "restStops": rest_stops,
        "speedAnomalies": speed_anomalies,
        "segments": segments,
    }


def build_index():
    dates = get_available_dates()
    index = []

    for date_formatted in dates:
        data_file = os.path.join(OUTPUT_DIR, f"{date_formatted}.json")
        if not os.path.isfile(data_file):
            continue
        try:
            with open(data_file) as f:
                data = json.load(f)
        except (json.JSONDecodeError, OSError):
            continue

        total_distance = data.get("totalDistance", 0)
        if total_distance < MIN_INDEX_DISTANCE:
            print(f"Excluding {date_formatted} from index: "
                  f"distance {total_distance}m < {MIN_INDEX_DISTANCE}m")
            continue

        index.append({
            "date": date_formatted,
            "projectName": data.get("projectName", ""),
            "totalDistance": total_distance,
            "recordCount": len(data.get("timeline", [])),
        })

    index.sort(key=lambda x: x["date"], reverse=True)

    os.makedirs(OUTPUT_DIR, exist_ok=True)
    with open(os.path.join(OUTPUT_DIR, "index.json"), "w", encoding="utf-8") as f:
        json.dump(index, f, ensure_ascii=False, indent=2)

    print(f"Generated index with {len(index)} dates")


def main():
    for date_formatted in get_available_dates():
        build(date_formatted)
    build_index()


if __name__ == "__main__":
    main()
