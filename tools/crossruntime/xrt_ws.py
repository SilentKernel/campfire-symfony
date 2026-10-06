"""A small Action Cable (actioncable-v1-json) client over a hand-rolled RFC 6455 WebSocket."""

from __future__ import annotations

import base64
import json
import os
import socket
import struct
import time
from urllib.parse import urlsplit


class CableClient:
    def __init__(self, base: str, cookie: str, origin: str | None = None, timeout: float = 10):
        u = urlsplit(base)
        self.sock = socket.create_connection((u.hostname, u.port), timeout=timeout)
        key = base64.b64encode(os.urandom(16)).decode()
        req = (f"GET /cable HTTP/1.1\r\nHost: {u.hostname}:{u.port}\r\nUpgrade: websocket\r\n"
               f"Connection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n"
               f"Sec-WebSocket-Protocol: actioncable-v1-json, actioncable-unsupported\r\n"
               f"Origin: {origin or base}\r\nCookie: {cookie}\r\n\r\n")
        self.sock.sendall(req.encode())
        buf = b""
        while b"\r\n\r\n" not in buf:
            chunk = self.sock.recv(4096)
            if not chunk:
                raise ConnectionError(f"handshake closed: {buf!r}")
            buf += chunk
        head, self.buf = buf.split(b"\r\n\r\n", 1)
        self.status_line = head.split(b"\r\n")[0].decode()
        if b" 101 " not in head.split(b"\r\n")[0]:
            raise ConnectionError(f"handshake refused: {head.decode(errors='replace')}")
        self.messages: list[dict] = []

    def _recv_exact(self, n: int) -> bytes:
        while len(self.buf) < n:
            chunk = self.sock.recv(65536)
            if not chunk:
                raise ConnectionError("closed")
            self.buf += chunk
        out, self.buf = self.buf[:n], self.buf[n:]
        return out

    def _frame(self) -> tuple[int, bytes]:
        b1, b2 = self._recv_exact(2)
        op, ln = b1 & 0x0F, b2 & 0x7F
        if ln == 126:
            ln = struct.unpack(">H", self._recv_exact(2))[0]
        elif ln == 127:
            ln = struct.unpack(">Q", self._recv_exact(8))[0]
        mask = self._recv_exact(4) if b2 & 0x80 else None
        data = self._recv_exact(ln)
        if mask:
            data = bytes(c ^ mask[i % 4] for i, c in enumerate(data))
        return op, data

    def send(self, payload: dict | str, op: int = 1) -> None:
        data = (json.dumps(payload) if isinstance(payload, dict) else payload).encode()
        mask = os.urandom(4)
        header = bytes([0x80 | op])
        n = len(data)
        if n < 126:
            header += bytes([0x80 | n])
        elif n < 65536:
            header += bytes([0x80 | 126]) + struct.pack(">H", n)
        else:
            header += bytes([0x80 | 127]) + struct.pack(">Q", n)
        self.sock.sendall(header + mask + bytes(c ^ mask[i % 4] for i, c in enumerate(data)))

    def recv(self, timeout: float) -> dict | None:
        """Next non-ping message, or None after `timeout` seconds."""
        deadline = time.time() + timeout
        while True:
            left = deadline - time.time()
            if left <= 0:
                return None
            self.sock.settimeout(left)
            try:
                op, data = self._frame()
            except (socket.timeout, TimeoutError):
                return None
            if op == 9:
                self.send(data.decode(errors="replace"), op=10)
                continue
            if op == 8:
                raise ConnectionError(f"server closed: {data!r}")
            if op != 1:
                continue
            msg = json.loads(data)
            if msg.get("type") == "ping":
                continue
            self.messages.append(msg)
            return msg

    def wait_for(self, pred, timeout: float) -> dict | None:
        deadline = time.time() + timeout
        while time.time() < deadline:
            m = self.recv(deadline - time.time())
            if m is None:
                return None
            if pred(m):
                return m
        return None

    def subscribe(self, identifier: dict, timeout: float = 10) -> bool:
        ident = json.dumps(identifier)
        self.send({"command": "subscribe", "identifier": ident})
        m = self.wait_for(lambda m: m.get("identifier") == ident and m.get("type") in ("confirm_subscription", "reject_subscription"), timeout)
        return bool(m and m["type"] == "confirm_subscription")

    def close(self) -> None:
        try:
            self.send("", op=8)
        except OSError:
            pass
        self.sock.close()
