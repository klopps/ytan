"""Unit tests: python -m unittest discover -s tools/tests"""

from __future__ import annotations

import logging
import os
import re
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import ytanlog  # noqa: E402

STAMP = re.compile(r"^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} ")


def write(path: str, first: int, last: int) -> None:
    with open(path, "w", newline="\n") as handle:
        handle.writelines(f"line {i}\n" for i in range(first, last + 1))


def read(path: str) -> list[str]:
    with open(path) as handle:
        return handle.read().splitlines()


class RotateTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = self.tmp.name
        self.path = os.path.join(self.dir, "job.log")

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def test_nothing_up_to_2000_lines(self) -> None:
        write(self.path, 1, 2000)
        self.assertEqual([], ytanlog.rotate(self.path))
        self.assertEqual(2000, ytanlog.count_lines(self.path))

    def test_oldest_1000_lines_into_next_numbered_archive(self) -> None:
        write(self.path, 1, 2001)
        write(os.path.join(self.dir, "job.3.log"), 1, 1)
        write(os.path.join(self.dir, "other.9.log"), 1, 1)
        self.assertEqual([os.path.join(self.dir, "job.4.log")], ytanlog.rotate(self.path))
        self.assertEqual([f"line {i}" for i in range(1, 1001)], read(os.path.join(self.dir, "job.4.log")))
        self.assertEqual([f"line {i}" for i in range(1001, 2002)], read(self.path))

    def test_long_file_gets_several_archives_oldest_first(self) -> None:
        write(self.path, 1, 4500)
        archives = ytanlog.rotate(self.path)
        self.assertEqual(["job.1.log", "job.2.log", "job.3.log"], [os.path.basename(a) for a in archives])
        self.assertEqual("line 2001", read(archives[2])[0])
        self.assertEqual(1500, len(read(self.path)))

    def test_append_handle_keeps_working(self) -> None:
        write(self.path, 1, 2500)
        with open(self.path, "a") as handle:
            ytanlog.rotate(self.path)
            handle.write("after rotation\n")
        lines = read(self.path)
        self.assertEqual("line 1001", lines[0])
        self.assertEqual("after rotation", lines[-1])


class LoggingTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.saved = (logging.getLogger().handlers[:], logging.getLogger().level, sys.excepthook)

    def tearDown(self) -> None:
        root = logging.getLogger()
        root.handlers, level, sys.excepthook = self.saved[0], self.saved[1], self.saved[2]
        root.setLevel(level)
        self.tmp.cleanup()

    def test_setup_writes_stamped_lines_also_for_tracebacks(self) -> None:
        ytanlog.setup("job", log_dir=self.tmp.name, console=False)
        log = logging.getLogger("x")
        log.info("hello")
        try:
            raise ValueError("boom")
        except ValueError:
            log.exception("failed")
        for handler in logging.getLogger().handlers:
            handler.flush()
        lines = read(os.path.join(self.tmp.name, "job.log"))
        self.assertTrue(lines[0].endswith(" INFO hello"))
        self.assertGreater(len(lines), 3)
        for line in lines:
            self.assertRegex(line, STAMP)
        self.assertTrue(lines[-1].endswith("ValueError: boom"))

    def test_log_file_rotates_while_writing(self) -> None:
        path = os.path.join(self.tmp.name, "job.log")
        write(path, 1, 1999)
        log_file = ytanlog.LogFile(path)
        log_file.write("2000")
        self.assertFalse(os.path.exists(os.path.join(self.tmp.name, "job.1.log")))
        log_file.write("2001")
        self.assertTrue(os.path.exists(os.path.join(self.tmp.name, "job.1.log")))
        self.assertEqual(1001, ytanlog.count_lines(path))

    def test_simple_log_echoes_to_stream(self) -> None:
        import io
        stream = io.StringIO()
        ytanlog.SimpleLog("tool", stream, log_dir=self.tmp.name)("one\ntwo")
        self.assertEqual(2, len(stream.getvalue().splitlines()))
        for line in read(os.path.join(self.tmp.name, "tool.log")):
            self.assertRegex(line, STAMP)


if __name__ == "__main__":
    unittest.main()
