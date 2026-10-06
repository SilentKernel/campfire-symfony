"""Docker orchestration for the cross-runtime check: one named volume holding the shared
storage (/rails/storage), and the Rails (campfire-reference:app) and Symfony
(campfire-symfony:app) containers started on it alternately or together.

Usage as a CLI (handy when poking by hand):
    python3 tools/crossruntime/xrt_docker.py seed|start rails|stop rails|sql "select 1"|cleanup
"""

from __future__ import annotations

import os
import subprocess
import sys
import time
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SEED = ROOT / "var/seed/default"
VOLUME = os.environ.get("XRT_VOLUME", "xrt-storage")
PREFIX = os.environ.get("XRT_PREFIX", "xrt")

APPS = {
    "rails": {"image": "campfire-reference:app", "port": int(os.environ.get("XRT_RAILS_PORT", "3101"))},
    "symfony": {"image": "campfire-symfony:app", "port": int(os.environ.get("XRT_SYMFONY_PORT", "3102"))},
}

# Seeding runs inside a throwaway container so file ownership is the image's uid 1000.
SEED_SCRIPT = r"""
set -e
S=/rails/storage
find "$S" -mindepth 1 -delete
mkdir -p "$S/db" "$S/files"
cp /seed/db/production.sqlite3 "$S/db/production.sqlite3"
cp -a /seed/storage/. "$S/files/"
chown -R 1000:1000 "$S"
"""


def env_file() -> dict[str, str]:
    env = {}
    for line in (ROOT / ".env.test").read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        env[k] = v.strip("'\"")
    return env


def run(cmd: list[str], check: bool = True, capture: bool = True, input: str | None = None) -> subprocess.CompletedProcess:
    return subprocess.run(cmd, check=check, text=True, capture_output=capture, input=input)


def name(app: str) -> str:
    return f"{PREFIX}-{app}"


def base_url(app: str) -> str:
    return f"http://127.0.0.1:{APPS[app]['port']}"


def seed() -> None:
    run(["docker", "volume", "rm", "-f", VOLUME], check=False)
    run(["docker", "volume", "create", VOLUME])
    run(["docker", "run", "--rm", "--user", "0", "-v", f"{VOLUME}:/rails/storage",
         "-v", f"{SEED}:/seed:ro", "--entrypoint", "sh", APPS["symfony"]["image"], "-c", SEED_SCRIPT])


EMPTY_SCRIPT = r"""
set -e
S=/rails/storage
mkdir -p "$S/db" "$S/files"
chown -R 1000:1000 "$S"
"""


def empty_volume() -> None:
    run(["docker", "volume", "rm", "-f", VOLUME], check=False)
    run(["docker", "volume", "create", VOLUME])
    run(["docker", "run", "--rm", "--user", "0", "-v", f"{VOLUME}:/rails/storage", "--entrypoint", "sh",
         APPS["symfony"]["image"], "-c", EMPTY_SCRIPT])


def is_running(app: str) -> bool:
    r = run(["docker", "ps", "-q", "--filter", f"name=^{name(app)}$"], check=False)
    return bool(r.stdout.strip())


def start(app: str, timeout: int = 120) -> float:
    stop(app)
    e = env_file()
    cmd = ["docker", "run", "-d", "--name", name(app), "-v", f"{VOLUME}:/rails/storage",
           "-e", f"SECRET_KEY_BASE={e['SECRET_KEY_BASE']}",
           "-e", f"VAPID_PUBLIC_KEY={e['VAPID_PUBLIC_KEY']}",
           "-e", f"VAPID_PRIVATE_KEY={e['VAPID_PRIVATE_KEY']}",
           "-e", "DISABLE_SSL=true",
           "-p", f"127.0.0.1:{APPS[app]['port']}:80", APPS[app]["image"]]
    run(cmd)
    t0 = time.time()
    while time.time() - t0 < timeout:
        try:
            with urllib.request.urlopen(base_url(app) + "/up", timeout=3) as r:
                if r.status == 200:
                    return time.time() - t0
        except Exception:
            pass
        if not is_running(app):
            raise RuntimeError(f"{name(app)} exited during boot:\n{logs(app)[-4000:]}")
        time.sleep(0.5)
    raise RuntimeError(f"{name(app)} did not answer /up within {timeout}s:\n{logs(app)[-4000:]}")


def stop(app: str) -> None:
    run(["docker", "stop", "-t", "20", name(app)], check=False)
    run(["docker", "rm", "-f", name(app)], check=False)


def logs(app: str) -> str:
    r = run(["docker", "logs", name(app)], check=False)
    return (r.stdout or "") + (r.stderr or "")


def exec_(app: str, *cmd: str) -> subprocess.CompletedProcess:
    return run(["docker", "exec", name(app), *cmd], check=False)


def sql(query: str) -> str:
    """Runs sqlite3 on the shared DB in a throwaway container (the host sqlite3 lacks FTS5).
    Only call while no app is running, or for read-only queries."""
    r = run(["docker", "run", "--rm", "-i", "-v", f"{VOLUME}:/rails/storage", "--entrypoint", "sqlite3",
             APPS["symfony"]["image"], "-batch", "/rails/storage/db/production.sqlite3"], check=False, input=query)
    return (r.stdout + r.stderr).strip()


def shell(cmd: str) -> str:
    r = run(["docker", "run", "--rm", "-v", f"{VOLUME}:/rails/storage", "--entrypoint", "sh",
             APPS["symfony"]["image"], "-c", cmd], check=False)
    return (r.stdout + r.stderr).strip()


def cleanup(remove_volume: bool = True) -> None:
    for app in APPS:
        stop(app)
    if remove_volume:
        run(["docker", "volume", "rm", "-f", VOLUME], check=False)


if __name__ == "__main__":
    a = sys.argv[1:]
    if a[0] == "seed":
        seed()
    elif a[0] == "start":
        print(f"booted in {start(a[1]):.1f}s")
    elif a[0] == "stop":
        stop(a[1])
    elif a[0] == "sql":
        print(sql(a[1]))
    elif a[0] == "sh":
        print(shell(a[1]))
    elif a[0] == "logs":
        print(logs(a[1]))
    elif a[0] == "cleanup":
        cleanup()
