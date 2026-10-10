#!/usr/bin/env bash

set -uo pipefail

#
# Einheitliche Ausgabe von ping/grep
#
export LC_ALL=C


#
# Konfiguration
#
LOGDIR="/var/lib/latency-monitor"
LOCKFILE="/tmp/latency-monitor.lock"

COUNT=10
INTERVAL=0.5
TIMEOUT=2


#
# Tagesdatei bestimmen
#
# Beispiel:
# latency-2026-09-09.csv
#
TODAY="$(date '+%Y-%m-%d')"
LOGFILE="${LOGDIR}/latency-${TODAY}.csv"


#
# Parallele Ausführungen verhindern
#
exec 9>"$LOCKFILE"

if ! flock -n 9; then
    exit 0
fi


#
# Ziele
#
TARGET_NAMES=(
    "FritzBox"
    "Cloudflare"
    "Heise"
    "Google"
)

declare -A TARGETS=(
    ["FritzBox"]="192.168.178.1"
    ["Cloudflare"]="1.1.1.1"
    ["Heise"]="193.99.144.80"
    ["Google"]="8.8.8.8"
)


#
# Verzeichnis anlegen
#
mkdir -p "$LOGDIR"


#
# Prüfen, ob die Tagesdatei neu ist
#
NEW_LOGFILE=0

if [[ ! -s "$LOGFILE" ]]; then

    printf '%s\n' \
        "timestamp,target,address,sent,received,loss_pct,rtt_min,rtt_avg,rtt_max,rtt_mdev" \
        > "$LOGFILE"

    NEW_LOGFILE=1
fi


#
# Einmaliger Warm-up bei neuer Tagesdatei
#
# Die Pings werden nicht geloggt.
#
if [[ "$NEW_LOGFILE" -eq 1 ]]; then

    for NAME in "${TARGET_NAMES[@]}"; do

        TARGET="${TARGETS[$NAME]}"

        ping \
            -n \
            -q \
            -c 3 \
            -i 0.3 \
            -W "$TIMEOUT" \
            "$TARGET" \
            >/dev/null 2>&1 || true

    done

    sleep 1
fi


#
# Eigentliche Messungen
#
for NAME in "${TARGET_NAMES[@]}"; do

    TARGET="${TARGETS[$NAME]}"

    TS="$(date '+%Y-%m-%d %H:%M:%S')"


    #
    # Ping
    #
    OUT="$(
        ping \
            -n \
            -q \
            -c "$COUNT" \
            -i "$INTERVAL" \
            -W "$TIMEOUT" \
            "$TARGET" \
            2>/dev/null || true
    )"


    #
    # Gesendete Pakete
    #
    SENT="$(
        grep -oP '\d+(?= packets transmitted)' <<< "$OUT" |
        head -n 1
    )"

    [[ -z "$SENT" ]] && SENT=0


    #
    # Empfangene Pakete
    #
    RECV="$(
        grep -oP '\d+(?= received)' <<< "$OUT" |
        head -n 1
    )"

    [[ -z "$RECV" ]] && RECV=0


    #
    # Paketverlust SELBST berechnen.
    #
    # Dadurch sind sent / received / loss immer konsistent.
    #
    if [[ "$SENT" -gt 0 ]]; then

        LOSS="$(
            awk \
                -v sent="$SENT" \
                -v recv="$RECV" \
                'BEGIN {
                    printf "%.1f", ((sent - recv) / sent) * 100
                }'
        )"

    else

        LOSS="100.0"

    fi


    #
    # RTT min/avg/max/mdev
    #
    RTT="$(
        grep -oP \
            'min/avg/max/mdev = \K[\d.]+/[\d.]+/[\d.]+/[\d.]+' \
            <<< "$OUT" ||
        true
    )"


    if [[ -n "$RTT" ]]; then

        IFS='/' read -r \
            RMIN \
            RAVG \
            RMAX \
            RMDEV \
            <<< "$RTT"

    else

        RMIN=""
        RAVG=""
        RMAX=""
        RMDEV=""

    fi


    #
    # Datensatz schreiben
    #
    printf '%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n' \
        "$TS" \
        "$NAME" \
        "$TARGET" \
        "$SENT" \
        "$RECV" \
        "$LOSS" \
        "$RMIN" \
        "$RAVG" \
        "$RMAX" \
        "$RMDEV" \
        >> "$LOGFILE"

done

