#!/usr/bin/env bash
# YTAN worker in Docker: one tools/ytan_worker.py tick every TICK_MINUTES. The same loop as
# ha-addon/ytan-worker/run.sh (keep them in step), but configured by environment variables
# (docker-compose.yml reads them from worker.env) instead of the add-on's /data/options.json.
set -u

: "${YTAN_SERVER_HOST:?YTAN_SERVER_HOST is not set (worker.env)}"
: "${YTAN_SERVER_USER:?YTAN_SERVER_USER is not set (worker.env)}"
: "${YTAN_SERVER_STORAGE:?YTAN_SERVER_STORAGE is not set (worker.env)}"
: "${WORKER_ID:?WORKER_ID is not set (worker.env): the name registered in Admin > Workers}"
WORKER_THREADS=${WORKER_THREADS:-2}
TICK_MINUTES=${TICK_MINUTES:-5}
WORKER_JOBS=${WORKER_JOBS:-}          # what this system can do, comma separated; empty = every job
WORKER_ADDRESS=${WORKER_ADDRESS:-}    # shown in Admin > Workers (display only)
WORKER_HOST_NAME=${WORKER_HOST_NAME:-$(hostname)}
export AROME_API_KEY=${AROME_API_KEY:-}

# Log format (same rule as every YTAN log): local "YYYY-MM-DD hh:mm:ss", then [source] - addon = this
# script, worker/wind/radar = the line comes from that log file.
log() { echo "$(date '+%Y-%m-%d %H:%M:%S') [addon] $*"; }

mkdir -p /data/storage/logs /data/ssh
KEY=/data/ssh/id_ed25519
if [ ! -f "$KEY" ]; then
    ssh-keygen -q -t ed25519 -N "" -C "ytan-worker@${WORKER_ID}" -f "$KEY"
    log "New SSH key created."
fi

log "YTAN worker ${WORKER_ID}: server ${YTAN_SERVER_USER}@${YTAN_SERVER_HOST}, a tick every ${TICK_MINUTES} min, ${WORKER_THREADS} thread(s)."
log "Register the name ${WORKER_ID} in Admin > Workers and put it into the lists of the jobs it should compute."
log "The text from command= on belongs into ~/.ssh/authorized_keys of ${YTAN_SERVER_USER} on ${YTAN_SERVER_HOST} (rsync below storage/ only):"
log "command=\"/usr/bin/rrsync ${YTAN_SERVER_STORAGE}\",restrict $(cat "${KEY}.pub")"

# the jobs and the worker write their own logs (tools/ytanlog.py); show them in the container log too,
# one tail per file with the file's name inserted after the time stamp
for name in worker wind radar; do
    file="/data/storage/logs/${name}.log"
    touch "$file"
    tail -n 0 -F "$file" 2>/dev/null \
        | sed -u -E "s/^([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}) /\1 [${name}] /" &
done

# worker.env is mounted read-only at /worker.env and these settings are read from it again before
# every tick, so changing one needs no container restart. Only WORKER_MEM_LIMIT is a property of the
# container itself (a cgroup limit Docker sets at creation): docker compose up -d.
reload_config() {
    local k line v
    if [ -f /worker.env ]; then
        for k in YTAN_SERVER_HOST YTAN_SERVER_USER YTAN_SERVER_STORAGE WORKER_ID WORKER_HOST_NAME TZ \
                 AROME_API_KEY WORKER_JOBS WORKER_ADDRESS WORKER_THREADS TICK_MINUTES; do
            line=$(grep "^${k}=" /worker.env | tail -1 | tr -d '\r')
            [ -n "$line" ] || continue
            v=${line#*=}; v=${v#\"}; v=${v%\"}
            printf -v "$k" '%s' "$v"
        done
    fi
    WORKER_THREADS=${WORKER_THREADS:-2}
    TICK_MINUTES=${TICK_MINUTES:-5}
    WORKER_HOST_NAME=${WORKER_HOST_NAME:-$(hostname)}
    [ -n "${TZ:-}" ] && export TZ
    export AROME_API_KEY=${AROME_API_KEY:-}
    if [ -z "$YTAN_SERVER_HOST" ] || [ -z "$YTAN_SERVER_USER" ] || [ -z "$WORKER_ID" ]; then
        log "worker.env: YTAN_SERVER_HOST, YTAN_SERVER_USER and WORKER_ID must not be empty - tick skipped."
        return 1
    fi
    ARGS=(--remote "${YTAN_SERVER_USER}@${YTAN_SERVER_HOST}" --ssh-key "$KEY" --known-hosts /data/ssh/known_hosts
          --workers "$WORKER_THREADS" --worker-id "$WORKER_ID" --host-name "$WORKER_HOST_NAME")
    [ -n "$WORKER_JOBS" ] && ARGS+=(--jobs "$WORKER_JOBS")
    [ -n "$WORKER_ADDRESS" ] && ARGS+=(--address "$WORKER_ADDRESS")
    return 0
}

while true; do
    started=$(date +%s)
    if reload_config; then
        # normally silent (it logs into worker.log); anything it prints anyway - a crash before
        # its log is set up - gets a time stamp too
        nice -n 10 python3 /app/tools/ytan_worker.py "${ARGS[@]}" 2>&1 \
            | while IFS= read -r line; do log "$line"; done
    fi
    elapsed=$(( $(date +%s) - started ))
    wait_s=$(( TICK_MINUTES * 60 - elapsed ))
    [ "$wait_s" -lt 10 ] && wait_s=10
    sleep "$wait_s"
done
