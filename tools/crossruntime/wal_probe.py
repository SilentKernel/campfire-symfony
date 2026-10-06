#!/usr/bin/env python3
"""WAL growth probe: does the -wal file get checkpointed back down after a write burst, per runtime?

Runs each app alone on a fresh copy of the seed: bursts of concurrent posts, then idle + one
single post (which triggers SQLite's autocheckpoint), sampling the -wal size each time.
Usage: python3 tools/crossruntime/wal_probe.py
"""

from __future__ import annotations

import sys
import threading
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
import crossruntime as X  # noqa: E402
import xrt_docker as D  # noqa: E402

D.VOLUME = "xrt-walprobe"


def wal_size() -> int:
    out = D.shell("stat -c %s /rails/storage/db/production.sqlite3-wal 2>/dev/null || echo 0")
    return int(out.split()[-1])


def burst(app: str, jar, token: str, n: int, threads: int) -> None:
    def w(k):
        c = X.Client(X.base(app), jar=jar.copy())
        for i in range(n):
            X.post_message(c, X.ROOM_HQ, f"<div>walprobe t{k} n{i}</div>", token)
    ts = [threading.Thread(target=w, args=(k,)) for k in range(threads)]
    [t.start() for t in ts]
    [t.join() for t in ts]


def main() -> None:
    try:
        for app in ("rails", "symfony"):
            D.cleanup()
            D.seed()
            D.start(app)
            c, _ = X.login(app, X.L("emails.david"))
            tok = X.csrf_for(c)
            samples = []
            for round_ in range(3):
                burst(app, c.jar, tok, 40, 4)
                after_burst = wal_size()
                time.sleep(3)
                X.post_message(c, X.ROOM_HQ, "<div>walprobe single</div>", tok)
                time.sleep(1)
                samples.append((after_burst, wal_size()))
            print(f"{app}: (wal after 160-post burst, wal after idle+1 post) x3 = {samples}", flush=True)
            D.stop(app)
    finally:
        D.cleanup()


if __name__ == "__main__":
    main()
