#!/usr/bin/env python3
"""Crash probe: SIGKILL a runtime in the middle of a write burst, repeatedly, and count messages
left without their FTS row (Message::Searchable indexes in after_create_commit, i.e. in a second
transaction, so a crash between the two commits leaves an unindexed message).

Usage: python3 tools/crossruntime/crash_probe.py rails|symfony [iterations]
"""

from __future__ import annotations

import random
import sys
import threading
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
import crossruntime as X  # noqa: E402
import xrt_docker as D  # noqa: E402

D.VOLUME = "xrt-crashprobe"


def missing() -> int:
    return int(D.sql("select count(*) from messages where id not in (select rowid from message_search_index);"))


def main() -> None:
    app = sys.argv[1]
    iterations = int(sys.argv[2]) if len(sys.argv) > 2 else 5
    try:
        D.cleanup()
        D.seed()
        total = 0
        for it in range(iterations):
            D.start(app)
            c, _ = X.login(app, X.L("emails.david"))
            tok = X.csrf_for(c)
            stop = threading.Event()
            acked = []

            def w(k):
                cc = X.Client(X.base(app), jar=c.jar.copy())
                i = 0
                while not stop.is_set():
                    try:
                        _, mid, _ = X.post_message(cc, X.ROOM_HQ, f"<div>crashprobe t{k} n{i}</div>", tok)
                        if mid:
                            acked.append(mid)
                    except Exception:
                        return
                    i += 1

            ts = [threading.Thread(target=w, args=(k,)) for k in range(8)]
            [t.start() for t in ts]
            time.sleep(random.uniform(1.5, 3.5))
            D.run(["docker", "kill", "-s", "KILL", D.name(app)], check=False)
            stop.set()
            [t.join(10) for t in ts]
            D.stop(app)
            m = missing()
            print(f"{app} iteration {it + 1}: {len(acked)} acknowledged posts, unindexed messages so far: {m}", flush=True)
            total = m
        print(f"{app}: {total} unindexed message(s) after {iterations} SIGKILLs")
    finally:
        D.cleanup()


if __name__ == "__main__":
    main()
