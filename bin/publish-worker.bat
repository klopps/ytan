@echo off
REM Builds and starts the YTAN worker container (docker\ytan-worker) on a Linux machine of the home
REM network, e.g. ytanworker: copies tools\ and docker\ytan-worker\ there (tar through ssh) and runs
REM docker compose up -d --build in ~/ytan-worker.
REM
REM Needs in .env: YTAN_WORKER_HOST (e.g. 192.168.178.12) and YTAN_WORKER_USER; the OpenSSH client with a key
REM login to that machine (ssh-copy-id) - no password is handled here. On the machine, once:
REM ~/ytan-worker/worker.env (from docker\ytan-worker\worker.env.example) - it holds a secret and is never
REM overwritten by this script. A second worker: set YTAN_WORKER_HOST/USER in the environment before the call.
REM NOTE: never put an unescaped less-than, greater-than or pipe character in a REM line of this file.
setlocal
set "ROOT_DIR=%~dp0.."
for /f "usebackq eol=# tokens=1,* delims==" %%A in ("%ROOT_DIR%\.env") do (
    if "%%A"=="YTAN_WORKER_HOST" if not defined YTAN_WORKER_HOST set "YTAN_WORKER_HOST=%%B"
    if "%%A"=="YTAN_WORKER_USER" if not defined YTAN_WORKER_USER set "YTAN_WORKER_USER=%%B"
)
if not defined YTAN_WORKER_HOST (echo YTAN_WORKER_HOST is not set and not in .env & exit /b 1)
if not defined YTAN_WORKER_USER (echo YTAN_WORKER_USER is not set and not in .env & exit /b 1)
set "TARGET=%YTAN_WORKER_USER%@%YTAN_WORKER_HOST%"

echo ==^> Checking the key login to %TARGET%
ssh -o BatchMode=yes -o ConnectTimeout=10 %TARGET% "echo ok" >nul
if errorlevel 1 (
    echo ==^> No key login to %TARGET%. Try by hand:  ssh -o BatchMode=yes %TARGET% echo ok
    echo      "Permission denied" = the public key is not in ~/.ssh/authorized_keys there - see docker\ytan-worker\README.md.
    echo      "Connection timed out" = wrong address, or the machine is off.
    exit /b 1
)
echo ==^> Checking Docker on %YTAN_WORKER_HOST%
ssh -o BatchMode=yes %TARGET% "docker info >/dev/null 2>&1"
if errorlevel 1 (
    echo ==^> Docker is missing or not usable for %YTAN_WORKER_USER% on %YTAN_WORKER_HOST%.
    echo      Install it ^(docs.docker.com, "Install Docker Engine on Debian"^) and add the user to the docker group:
    echo      sudo usermod -aG docker %YTAN_WORKER_USER%  - then log in again.
    exit /b 1
)
ssh -o BatchMode=yes %TARGET% "test -f ytan-worker/worker.env"
if errorlevel 1 (
    echo ==^> ~/ytan-worker/worker.env is missing on %YTAN_WORKER_HOST%. Create it once from docker\ytan-worker\worker.env.example:
    echo      ssh %TARGET% "mkdir -p ytan-worker"
    echo      scp docker\ytan-worker\worker.env.example %TARGET%:ytan-worker/worker.env
    echo      then edit it there ^(WORKER_ID, AROME_API_KEY^) and run this script again.
    exit /b 1
)

echo ==^> Copying tools and docker\ytan-worker to %TARGET%:~/ytan-worker and starting the container
REM the old tools and docker directories are replaced as a whole (worker.env and data stay)
tar -cf - -C "%ROOT_DIR%" --exclude=.venv --exclude=__pycache__ --exclude=tests --exclude=*.pyc --exclude=*.zip --exclude=*.pkl tools docker/ytan-worker | ssh -o BatchMode=yes %TARGET% "mkdir -p ytan-worker && cd ytan-worker && rm -rf tools docker && tar -xf - && if [ ! -f worker.env ]; then echo 'worker.env is missing: copy docker/ytan-worker/worker.env.example to ~/ytan-worker/worker.env and edit it, then run this again.'; exit 3; fi && docker compose -f docker/ytan-worker/docker-compose.yml --env-file worker.env up -d --build"
if errorlevel 1 (
    echo ==^> Failed. Is the key login working? (ssh %TARGET% - and is Docker installed there?)
    exit /b 1
)
echo ==^> Done. Log: ssh %TARGET% docker logs -f ytan-worker
exit /b 0
