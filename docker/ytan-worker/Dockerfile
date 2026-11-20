# YTAN worker image for a plain Linux machine with Docker (no Home Assistant). Same recipe as
# ha-addon/ytan-worker/Dockerfile: Debian (glibc), because numpy/scipy/imagecodecs/h5py/opencv come as
# manylinux wheels (amd64 and aarch64) - nothing to compile. Build context = the repository root
# (docker-compose.yml sets it), see README.md.
FROM python:3.13-slim-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends openssh-client rsync \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
# all jobs' packages (rain radar + wind), so giving another job to this worker needs no rebuild
COPY tools/requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt

COPY tools /app/tools
COPY docker/ytan-worker/worker-loop.sh /worker-loop.sh
# the jobs use ROOT/storage like on the server; it lives in the volume /data (kept across rebuilds)
RUN chmod +x /worker-loop.sh && ln -s /data/storage /app/storage

CMD ["/worker-loop.sh"]
