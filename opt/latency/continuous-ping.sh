#!/usr/bin/env bash

set -uo pipefail
export LC_ALL=C


#
# Konfiguration
#
LOGDIR="/var/lib/latency-monitor/continuous"

INTERVAL=0.5


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


mkdir -p "$LOGDIR"


#
# Zeitstempel aus ping -D umwandeln
#
format_timestamp()
{
    local EPOCH="$1"

    local SEC="${EPOCH%%.*}"
    local FRACTION="${EPOCH#*.}"

    local MS="${FRACTION:0:3}"

    while [[ ${#MS} -lt 3 ]]; do
        MS="${MS}0"
    done

    printf '%s.%s' \
        "$(date -d "@${SEC}" '+%Y-%m-%d %H:%M:%S')" \
        "$MS"
}


#
# CSV-Zeile schreiben
#
write_csv()
{
    local TIMESTAMP="$1"
    local NAME="$2"
    local TARGET="$3"
    local SEQ="$4"
    local STATUS="$5"
    local RTT="$6"

    local DAY
    DAY="$(date '+%Y-%m-%d')"

    local LOGFILE="${LOGDIR}/continuous-${DAY}.csv"
    local LOCKFILE="${LOGFILE}.lock"


    (
        flock -x 200

        if [[ ! -s "$LOGFILE" ]]; then

            printf '%s\n' \
                "timestamp,target,address,seq,status,rtt_ms" \
                > "$LOGFILE"

        fi


        printf '%s,%s,%s,%s,%s,%s\n' \
            "$TIMESTAMP" \
            "$NAME" \
            "$TARGET" \
            "$SEQ" \
            "$STATUS" \
            "$RTT" \
            >> "$LOGFILE"

    ) 200>"$LOCKFILE"
}


#
# Einzelnes Ziel dauerhaft pingen
#
run_ping()
{
    local NAME="$1"
    local TARGET="$2"

    local LAST_SEQ=""
    local LAST_TIMESTAMP=""


    ping \
        -D \
        -n \
        -i "$INTERVAL" \
        "$TARGET" \
        2>/dev/null |
    while IFS= read -r LINE
    do

        #
        # Beispiel:
        #
        # [1788960421.123456] 64 bytes from 1.1.1.1:
        # icmp_seq=123 ttl=58 time=10.521 ms
        #
        if [[ "$LINE" =~ ^\[([0-9]+\.[0-9]+)\].*icmp_seq=([0-9]+).*time[=\<]([0-9.]+)[[:space:]]ms ]]; then

            EPOCH="${BASH_REMATCH[1]}"
            SEQ="${BASH_REMATCH[2]}"
            RTT="${BASH_REMATCH[3]}"

            TIMESTAMP="$(format_timestamp "$EPOCH")"


            #
            # Falls bereits eine vorherige Sequenz bekannt ist:
            # Prüfen, ob Sequenzen übersprungen wurden.
            #
            if [[ -n "$LAST_SEQ" ]]; then

                EXPECTED=$((LAST_SEQ + 1))


                #
                # Beispiel:
                #
                # vorher: 100
                # jetzt:   103
                #
                # dann fehlen:
                #
                # 101
                # 102
                #
                if (( SEQ > EXPECTED )); then

                    MISSING="$EXPECTED"

                    while (( MISSING < SEQ )); do

                        #
                        # Für echten Loss verwenden wir den
                        # Zeitpunkt der nächsten empfangenen Antwort.
                        #
                        # Dadurch ist klar:
                        # spätestens zu diesem Zeitpunkt stand fest,
                        # dass die Sequenz fehlt.
                        #
                        write_csv \
                            "$TIMESTAMP" \
                            "$NAME" \
                            "$TARGET" \
                            "$MISSING" \
                            "LOSS" \
                            ""

                        MISSING=$((MISSING + 1))

                    done

                fi

            fi


            #
            # Normale Antwort speichern
            #
            write_csv \
                "$TIMESTAMP" \
                "$NAME" \
                "$TARGET" \
                "$SEQ" \
                "OK" \
                "$RTT"


            LAST_SEQ="$SEQ"
            LAST_TIMESTAMP="$TIMESTAMP"

        fi

    done
}


#
# Alle Ziele parallel starten
#
PIDS=()


for NAME in "${TARGET_NAMES[@]}"
do

    run_ping \
        "$NAME" \
        "${TARGETS[$NAME]}" &

    PIDS+=("$!")

done


#
# Sauber beenden
#
cleanup()
{
    echo
    echo "Dauerping wird beendet ..."

    for PID in "${PIDS[@]}"
    do
        kill "$PID" 2>/dev/null || true
    done

    wait 2>/dev/null || true

    exit 0
}


trap cleanup SIGINT SIGTERM


echo "Dauerping gestartet."
echo
echo "Ziele:"

for NAME in "${TARGET_NAMES[@]}"
do
    echo "  ${NAME}: ${TARGETS[$NAME]}"
done

echo
echo "Intervall: ${INTERVAL} Sekunden"
echo "Log-Verzeichnis: ${LOGDIR}"
echo
echo "LOSS wird jetzt aus echten icmp_seq-Lücken ermittelt."
echo "Keine vorläufigen 'no answer yet'-Meldungen mehr."
echo
echo "Beenden mit CTRL+C"
echo


wait

