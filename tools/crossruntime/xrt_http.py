"""Minimal stdlib HTTP client with a portable cookie jar: the same jar can be pointed at the
Rails app and then at the Symfony app (both are served on 127.0.0.1, so a browser would send
the same cookies to either when one replaces the other)."""

from __future__ import annotations

import html
import http.client
import re
import struct
import uuid
import zlib
from dataclasses import dataclass, field
from urllib.parse import urlencode, urlsplit

UA = ("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36")
TURBO_STREAM = "text/vnd.turbo-stream.html, text/html, application/xhtml+xml"


@dataclass
class Response:
    status: int
    headers: list[tuple[str, str]]
    body: bytes
    url: str

    def header(self, name: str) -> str | None:
        for k, v in self.headers:
            if k.lower() == name.lower():
                return v
        return None

    @property
    def text(self) -> str:
        return self.body.decode("utf-8", "replace")

    @property
    def location(self) -> str | None:
        return self.header("Location")

    def csrf(self) -> str | None:
        m = re.search(r'<meta name="csrf-token" content="([^"]+)"', self.text)
        return html.unescape(m.group(1)) if m else None

    def short(self) -> str:
        loc = f" -> {self.location}" if self.location else ""
        return f"{self.status}{loc}"


@dataclass
class Jar:
    cookies: dict[str, str] = field(default_factory=dict)

    def copy(self) -> "Jar":
        return Jar(dict(self.cookies))

    def header(self) -> str:
        return "; ".join(f"{k}={v}" for k, v in self.cookies.items())

    def absorb(self, headers: list[tuple[str, str]]) -> None:
        for k, v in headers:
            if k.lower() != "set-cookie":
                continue
            first, *attrs = [p.strip() for p in v.split(";")]
            name, _, value = first.partition("=")
            attrs_l = [a.lower() for a in attrs]
            expired = value == "" or any(a.startswith("max-age=0") for a in attrs_l) or any(
                a.startswith("expires=") and "1970" in a for a in attrs_l)
            if expired:
                self.cookies.pop(name, None)
            else:
                self.cookies[name] = value


class Client:
    def __init__(self, base: str, jar: Jar | None = None, label: str = ""):
        self.base = base.rstrip("/")
        self.jar = jar if jar is not None else Jar()
        self.label = label
        self.last_csrf: str | None = None

    def request(self, method: str, path: str, body: bytes | None = None, headers: dict | None = None,
                follow: bool = False, max_redirects: int = 5) -> Response:
        url = path if path.startswith("http") else self.base + path
        for _ in range(max_redirects + 1):
            u = urlsplit(url)
            conn = http.client.HTTPConnection(u.hostname, u.port, timeout=60)
            h = {"User-Agent": UA, "Accept": "text/html,application/xhtml+xml"}
            if self.jar.cookies:
                h["Cookie"] = self.jar.header()
            h.update(headers or {})
            target = u.path + (f"?{u.query}" if u.query else "")
            conn.request(method, target, body=body, headers=h)
            r = conn.getresponse()
            data = r.read()
            hdrs = r.getheaders()
            conn.close()
            self.jar.absorb(hdrs)
            resp = Response(r.status, hdrs, data, url)
            token = resp.csrf() if b"csrf-token" in data[:20000] else None
            if token:
                self.last_csrf = token
            if follow and r.status in (301, 302, 303, 307, 308) and resp.location:
                loc = resp.location
                if loc.startswith("/"):
                    loc = f"{u.scheme}://{u.netloc}{loc}"
                # Redirects only make sense within one app; never follow off-host.
                if urlsplit(loc).netloc != u.netloc:
                    return resp
                url, method, body = loc, "GET", None
                headers = {k: v for k, v in (headers or {}).items() if k.lower() == "accept"}
                continue
            return resp
        return resp

    def get(self, path: str, follow: bool = False, **kw) -> Response:
        return self.request("GET", path, follow=follow, **kw)

    def post_form(self, path: str, fields: list[tuple[str, str]] | dict, csrf: str | None = None,
                  method_override: str | None = None, accept: str | None = None, follow: bool = False,
                  extra_headers: dict | None = None) -> Response:
        items = list(fields.items()) if isinstance(fields, dict) else list(fields)
        if method_override:
            items.insert(0, ("_method", method_override))
        if csrf is not None:
            items.append(("authenticity_token", csrf))
        h = {"Content-Type": "application/x-www-form-urlencoded"}
        if accept:
            h["Accept"] = accept
        h.update(extra_headers or {})
        return self.request("POST", path, body=urlencode(items).encode(), headers=h, follow=follow)

    def post_multipart(self, path: str, fields: list[tuple[str, str]], files: list[tuple[str, str, str, bytes]],
                       csrf: str | None = None, method_override: str | None = None, accept: str | None = None,
                       follow: bool = False) -> Response:
        items = list(fields)
        if method_override:
            items.insert(0, ("_method", method_override))
        if csrf is not None:
            items.append(("authenticity_token", csrf))
        boundary = "----xrt" + uuid.uuid4().hex
        out = bytearray()
        for k, v in items:
            out += f"--{boundary}\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n".encode()
            out += str(v).encode() + b"\r\n"
        for k, filename, ctype, data in files:
            out += (f"--{boundary}\r\nContent-Disposition: form-data; name=\"{k}\"; filename=\"{filename}\"\r\n"
                    f"Content-Type: {ctype}\r\n\r\n").encode()
            out += data + b"\r\n"
        out += f"--{boundary}--\r\n".encode()
        h = {"Content-Type": f"multipart/form-data; boundary={boundary}"}
        if accept:
            h["Accept"] = accept
        return self.request("POST", path, body=bytes(out), headers=h, follow=follow)


def png(width: int, height: int, seed: int = 0) -> bytes:
    """A deterministic RGB gradient PNG (no Pillow needed)."""
    rows = bytearray()
    for y in range(height):
        rows.append(0)
        for x in range(width):
            rows += bytes(((x * 255 // max(width - 1, 1) + seed) % 256,
                           (y * 255 // max(height - 1, 1) + 3 * seed) % 256,
                           ((x + y + 7 * seed) * 3) % 256))

    def chunk(kind: bytes, data: bytes) -> bytes:
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)

    ihdr = struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", ihdr) + chunk(b"IDAT", zlib.compress(bytes(rows), 6)) + chunk(b"IEND", b"")


def image_kind(data: bytes) -> str:
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "webp"
    if data[:8] == b"\x89PNG\r\n\x1a\n":
        return "png"
    if data[:3] == b"\xff\xd8\xff":
        return "jpeg"
    if b"<svg" in data[:500]:
        return "svg"
    return "unknown"
