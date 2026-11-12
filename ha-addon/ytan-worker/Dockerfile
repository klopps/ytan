# YTAN worker add-on image. Debian (glibc) rather than Home Assistant's Alpine base:
# numpy/scipy/imagecodecs/h5py/opencv come as manylinux wheels for aarch64 - nothing to compile.
ARG BUILD_FROM
FROM python:3.13-slim-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends openssh-client rsync \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
# all jobs' packages (rain radar + wind), so moving another job to the worker needs no rebuild
COPY tools/radar/requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt

COPY tools /app/tools
COPY run.sh /run.sh
# the jobs use ROOT/storage like on the server; it lives in the add-on's /data (kept, not backed up)
RUN chmod +x /run.sh && ln -s /data/storage /app/storage

CMD ["/run.sh"]
