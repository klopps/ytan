#!/usr/bin/env bash
# YTAN worker add-on: one tools/ytan_worker.py tick every tick_minutes.
set -u

OPTIONS=/data/options.json
opt() { python3 -c 'import json, sys; print(json.load(open(sys.argv[1]))[sys.argv[2]])' "$OPTIONS" "$1"; }
# an option that an older options file may not have yet: its value or the default
opt_or() { python3 -c 'import json, sys; print(json.load(open(sys.argv[1])).get(sys.argv[2]) or sys.argv[3])' "$OPTIONS" "$1" "$2"; }

SERVER_HOST=$(opt server_host)
SERVER_USER=$(opt server_user)
SERVER_STORAGE=$(opt server_storage)
WORKERS=$(opt workers)
TICK_MINUTES=$(opt tick_minutes)
HOST_NAME=$(opt host_name)
# this worker's unique name (registered in /admin/workers), what it can do (empty = every job), an address to show there
WORKER_ID=$(opt_or worker_id homeassistant)
JOBS=$(opt_or jobs "")
ADDRESS=$(opt_or address "")
# optional (an older options file has none): the AROME key the wind job reads from its environment
AROME_API_KEY=$(opt_or arome_api_key "")
export AROME_API_KEY

# Add-on log format (same rule as every YTAN log): local "YYYY-MM-DD hh:mm:ss", then
# [source] - addon = this script, worker/wind/radar = the line comes from that log file.
log() { echo "$(date '+%Y-%m-%d %H:%M:%S') [addon] $*"; }

mkdir -p /data/storage/logs /data/ssh
KEY=/data/ssh/id_ed25519
if [ ! -f "$KEY" ]; then
    ssh-keygen -q -t ed25519 -N "" -C "ytan-worker@${WORKER_ID}" -f "$KEY"
    log "New SSH key created."
fi

log "YTAN worker ${WORKER_ID}: server ${SERVER_USER}@${SERVER_HOST}, a tick every ${TICK_MINUTES} min, ${WORKERS} worker(s)."
log "Register the name ${WORKER_ID} in Admin > Workers and put it into the lists of the jobs it should compute."
log "The text from command= on belongs into ~/.ssh/authorized_keys of ${SERVER_USER} on ${SERVER_HOST} (rsync below storage/ only):"
log "command=\"/usr/bin/rrsync ${SERVER_STORAGE}\",restrict $(cat "${KEY}.pub")"

# the jobs and the worker write their own logs (tools/ytanlog.py); show them in the add-on log too,
# one tail per file (a tail over several files prints "==> file <==" headers instead of saying
# which job wrote a line) with the file's name inserted after the time stamp
for name in worker wind radar; do
    file="/data/storage/logs/${name}.log"
    touch "$file"
    tail -n 0 -F "$file" 2>/dev/null \
        | sed -u -E "s/^([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}) /\1 [${name}] /" &
done

ARGS=(--remote "${SERVER_USER}@${SERVER_HOST}" --ssh-key "$KEY" --known-hosts /data/ssh/known_hosts
      --workers "$WORKERS" --worker-id "$WORKER_ID" --host-name "$HOST_NAME" --ha-sensors)
[ -n "$JOBS" ] && ARGS+=(--jobs "$JOBS")
[ -n "$ADDRESS" ] && ARGS+=(--address "$ADDRESS")

while true; do
    started=$(date +%s)
    # normally silent (it logs into worker.log); anything it prints anyway - a crash before
    # its log is set up - gets a time stamp too
    nice -n 10 python3 /app/tools/ytan_worker.py "${ARGS[@]}" 2>&1 \
        | while IFS= read -r line; do log "$line"; done
    elapsed=$(( $(date +%s) - started ))
    wait_s=$(( TICK_MINUTES * 60 - elapsed ))
    [ "$wait_s" -lt 10 ] && wait_s=10
    sleep "$wait_s"
done
