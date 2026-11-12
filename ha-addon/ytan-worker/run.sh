#!/usr/bin/env bash
# YTAN worker add-on: one tools/ytan_worker.py tick every tick_minutes.
set -u

OPTIONS=/data/options.json
opt() { python3 -c 'import json, sys; print(json.load(open(sys.argv[1]))[sys.argv[2]])' "$OPTIONS" "$1"; }

SERVER_HOST=$(opt server_host)
SERVER_USER=$(opt server_user)
SERVER_STORAGE=$(opt server_storage)
WORKERS=$(opt workers)
TICK_MINUTES=$(opt tick_minutes)
HOST_NAME=$(opt host_name)

mkdir -p /data/storage/logs /data/ssh
KEY=/data/ssh/id_ed25519
if [ ! -f "$KEY" ]; then
    ssh-keygen -q -t ed25519 -N "" -C "ytan-worker@${HOST_NAME}" -f "$KEY"
    echo "New SSH key created."
fi

echo "YTAN worker: server ${SERVER_USER}@${SERVER_HOST}, a tick every ${TICK_MINUTES} min, ${WORKERS} worker(s)."
echo "This line belongs into ~/.ssh/authorized_keys of ${SERVER_USER} on ${SERVER_HOST} (rsync below storage/ only):"
echo "command=\"/usr/bin/rrsync ${SERVER_STORAGE}\",restrict $(cat "${KEY}.pub")"

# the jobs and the worker write their own logs (tools/ytanlog.py); show them in the add-on log too
for name in worker wind radar; do touch "/data/storage/logs/${name}.log"; done
tail -n 0 -F /data/storage/logs/worker.log /data/storage/logs/wind.log /data/storage/logs/radar.log &

while true; do
    started=$(date +%s)
    nice -n 10 python3 /app/tools/ytan_worker.py \
        --remote "${SERVER_USER}@${SERVER_HOST}" \
        --ssh-key "$KEY" --known-hosts /data/ssh/known_hosts \
        --workers "$WORKERS" --host-name "$HOST_NAME" --ha-sensors
    elapsed=$(( $(date +%s) - started ))
    wait_s=$(( TICK_MINUTES * 60 - elapsed ))
    [ "$wait_s" -lt 10 ] && wait_s=10
    sleep "$wait_s"
done
