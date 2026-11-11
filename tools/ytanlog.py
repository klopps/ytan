"""YTAN's log files for the Python tools (radar, wind, ytan_jobs, water).

Same rules as the PHP side (src/Service/Log/LogWriter.php, LogRotation.php):
- every log is a file in storage/logs that the program writes ITSELF (append,
  never overwritten) - not a shell redirection;
- every line starts with the local date and time, "YYYY-MM-DD hh:mm:ss"
  (continuation lines of a multi-line message, e.g. a traceback, too);
- once a file has more than MAX_LINES lines, its oldest ARCHIVE_LINES lines
  move into a new file NAME.N.log (N = the next free number), as often as
  needed - the file is rewritten in place, so a shell redirection that holds
  it open in append mode keeps working;
- more than MAX_ARCHIVES archives: all but the newest KEEP_ARCHIVES go into
  NAME.A-B.zip under their own names (deleted once the ZIP holds them), the
  numbers keep counting.

Console output only when stderr is a terminal: cron and /admin/radar start the
jobs with their output redirected (old cron lines even into the same file),
which would otherwise write every line twice.
"""

from __future__ import annotations

import logging
import os
import re
import sys
import time
import zipfile

MAX_LINES = 2000
ARCHIVE_LINES = 1000
MAX_ARCHIVES = 20
KEEP_ARCHIVES = 10

ROOT = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
LOG_DIR = os.path.join(ROOT, "storage", "logs")


def log_path(name: str, log_dir: str | None = None) -> str:
    return os.path.join(log_dir or LOG_DIR, name + ".log")


def stamp(when: float | None = None) -> str:
    return time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(when))


def stamp_lines(text: str, when: float | None = None) -> str:
    """Every line of text with the date/time in front, ending in a newline."""
    prefix = stamp(when) + " "
    return "".join(prefix + line + "\n" for line in text.rstrip("\n").split("\n"))


def count_lines(path: str) -> int:
    try:
        with open(path, "rb") as handle:
            return sum(chunk.count(b"\n") for chunk in iter(lambda: handle.read(1 << 20), b""))
    except FileNotFoundError:
        return 0


def _next_archive_number(path: str) -> int:
    folder, base = os.path.split(path)
    stem = base[:-4] if base.endswith(".log") else base
    # NAME.N.log, and NAME.A-B.zip of packed ones: numbering never starts again after compact()
    pattern = re.compile(re.escape(stem) + r"\.(?:\d+-)?(\d+)\.(?:log|zip)$")
    numbers = [int(m.group(1)) for m in (pattern.match(f) for f in os.listdir(folder or ".")) if m]
    return max(numbers, default=0) + 1


def rotate(path: str, max_lines: int = MAX_LINES, archive_lines: int = ARCHIVE_LINES) -> list[str]:
    """Moves the oldest lines of a file with more than max_lines lines into archives; returns their paths."""
    total = count_lines(path)
    if total <= max_lines:
        return []
    chunks = -(-(total - max_lines) // archive_lines)  # ceil
    stem = path[:-4] if path.endswith(".log") else path
    number = _next_archive_number(path)
    archives: list[str] = []
    with open(path, "r+b") as handle:
        for _ in range(chunks):
            archive = f"{stem}.{number}.log"
            with open(archive, "wb") as out:
                for _ in range(archive_lines):
                    out.write(handle.readline())
            archives.append(archive)
            number += 1
        rest = handle.read()
        handle.seek(0)
        handle.write(rest)
        handle.truncate()
    compact(path)
    return archives


def archives(path: str) -> dict[int, str]:
    """number -> path of the NAME.N.log archives, oldest first."""
    folder, base = os.path.split(path)
    stem = base[:-4] if base.endswith(".log") else base
    pattern = re.compile(re.escape(stem) + r"\.(\d+)\.log$")
    found = {int(m.group(1)): os.path.join(folder, m.group(0))
             for m in (pattern.match(f) for f in os.listdir(folder or ".")) if m}
    return dict(sorted(found.items()))


def compact(path: str, max_archives: int = MAX_ARCHIVES, keep: int = KEEP_ARCHIVES) -> str | None:
    """More than max_archives archives: all but the newest keep go into NAME.A-B.zip, under their own
    names, and are deleted once the ZIP holds them. The remaining ones keep their numbers."""
    found = archives(path)
    if len(found) <= max_archives:
        return None
    old = dict(list(found.items())[:len(found) - keep])
    numbers = list(old)
    stem = path[:-4] if path.endswith(".log") else path
    zip_path = f"{stem}.{numbers[0]}-{numbers[-1]}.zip"
    try:
        with zipfile.ZipFile(zip_path, "x", zipfile.ZIP_DEFLATED) as archive:
            for file in old.values():
                archive.write(file, arcname=os.path.basename(file))
        with zipfile.ZipFile(zip_path) as archive:
            if sorted(archive.namelist()) != sorted(os.path.basename(f) for f in old.values()):
                return None
    except FileExistsError:
        return None
    for file in old.values():
        os.remove(file)
    return zip_path


class StampedFormatter(logging.Formatter):
    """'YYYY-MM-DD hh:mm:ss LEVEL message', the date in front of EVERY line."""

    def format(self, record: logging.LogRecord) -> str:
        text = record.levelname + " " + record.getMessage()
        if record.exc_info:
            text += "\n" + self.formatException(record.exc_info)
        if record.stack_info:
            text += "\n" + self.formatStack(record.stack_info)
        return stamp_lines(text, record.created).rstrip("\n")


class LogFile:
    """Appends stamped lines to one log file and keeps it rotated."""

    def __init__(self, path: str, max_lines: int = MAX_LINES, archive_lines: int = ARCHIVE_LINES):
        self.path = path
        self.max_lines = max_lines
        self.archive_lines = archive_lines
        os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
        self._rotate()

    def _rotate(self) -> None:
        try:
            rotate(self.path, self.max_lines, self.archive_lines)
        except OSError:
            pass  # e.g. Windows refusing a file another process holds; next time
        self.lines = count_lines(self.path)

    def write_stamped(self, stamped: str) -> None:
        with open(self.path, "a", encoding="utf-8", newline="\n") as handle:
            handle.write(stamped)
        self.lines += stamped.count("\n")
        if self.lines > self.max_lines:
            self._rotate()

    def write(self, message: str) -> None:
        self.write_stamped(stamp_lines(message))


class LogFileHandler(logging.Handler):
    def __init__(self, log_file: LogFile):
        super().__init__()
        self.log_file = log_file
        self.setFormatter(StampedFormatter())

    def emit(self, record: logging.LogRecord) -> None:
        try:
            self.log_file.write_stamped(self.format(record) + "\n")
        except Exception:
            self.handleError(record)


def setup(name: str, level: int = logging.INFO, log_dir: str | None = None,
          console: bool | None = None) -> LogFile:
    """Root logger -> storage/logs/{name}.log (+ stderr on a terminal); uncaught exceptions are logged too."""
    log_file = LogFile(log_path(name, log_dir))
    root = logging.getLogger()
    root.setLevel(level)
    for handler in list(root.handlers):
        root.removeHandler(handler)
    root.addHandler(LogFileHandler(log_file))
    if console if console is not None else sys.stderr.isatty():
        stream = logging.StreamHandler(sys.stderr)
        stream.setFormatter(StampedFormatter())
        root.addHandler(stream)

    def excepthook(kind, value, trace):
        if issubclass(kind, KeyboardInterrupt):
            sys.__excepthook__(kind, value, trace)
            return
        logging.getLogger(name).critical("uncaught exception", exc_info=(kind, value, trace))

    sys.excepthook = excepthook
    return log_file


class SimpleLog:
    """For scripts that print progress lines (tools/water): file always, console stream as before."""

    def __init__(self, name: str, stream=None, log_dir: str | None = None):
        self.file = LogFile(log_path(name, log_dir))
        self.stream = stream

    def __call__(self, message: str) -> None:
        stamped = stamp_lines(message)
        self.file.write_stamped(stamped)
        if self.stream is not None:
            self.stream.write(stamped)
            self.stream.flush()
