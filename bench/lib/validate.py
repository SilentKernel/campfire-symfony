#!/usr/bin/env python3
"""Validation for bench/run: a run only counts if the app really did the work it was timed on.

  validate.py thumbnail BASE COOKIE ROOM CSRF FILE   -> JSON: one more upload, then its thumbnail
  validate.py rep RESULT.json                        -> adds "validation" to a rep's JSON, prints it
  validate.py page BASE COOKIE NAME=PATH...         -> JSON: what each page contains (untimed GETs)
  validate.py writes-mark DB                         -> JSON: the newest message id before a write run
  validate.py writes DB MARK ACKED [SETTLE_SECS]     -> JSON: were ACKED acknowledged posts persisted?

`loadgen upload` times POST → first <img> in the response, but the first <img> of a rendered
message is the author's avatar (messages/_message.html.erb), not the attachment. `thumbnail` posts
the same file the same way, finds the attachment's own <img class="message__attachment"> (Rails:
Messages::AttachmentPresentation, representation(:thumb)), follows its redirects and checks that an
image comes back. It runs after the timed suites, so it doesn't change what they measure.

`rep` checks, per rep:
  scrape   a CSRF token, Turbo stream sources and a stylesheet were found on the room page
  http     every response 2xx/3xx, no transport errors (timeouts, resets), at least one response
  cable    every client subscribed (no failures), and every message, paced and saturated, reached
           every client
  upload   every timed upload POST < 400, and the attachment thumbnail served as an image
  writes   (when recorded) every acknowledged post_message request is a new message row whose rich
           text body holds its "bench write N" text and which is in the full-text index, and
           PRAGMA integrity_check is ok

`page` fetches each page once, as loadgen does (cookie, Accept-Encoding: gzip), and records its
status, wire and decoded size, the messages it shows (data-message-id / id="message_N", in order) and
its element counts (all start tags, and per tag), so the report can tell whether two apps did the
same work per request (same message window, comparable markup) or not.

`writes` reads the database read-only (bench/run calls it on the live database, as the database
file's owner; bench/bin/verify-writes after the app has stopped). The schema is the Rails one, which
every port keeps: messages, action_text_rich_texts (record_type 'Message', name 'body') and the FTS5
table message_search_index, whose rowid is the message id. New rows are those with an id above the
mark taken before the posts. A port that indexed or wrote asynchronously gets up to SETTLE_SECS
(default 10) for the counts to line up; the time it took is recorded.
"""
import collections, gzip, hashlib, json, re, sqlite3, sys, time, urllib.error, urllib.request, uuid


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


OPENER = urllib.request.build_opener(NoRedirect)


def request(url, method="GET", headers=None, body=None):
    req = urllib.request.Request(url, data=body, method=method, headers=headers or {})
    try:
        with OPENER.open(req, timeout=60) as r:
            return r.status, dict(r.headers), r.read()
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read()


def image_kind(data):
    if data[:3] == b"\xff\xd8\xff":
        return "jpeg"
    if data[:8] == b"\x89PNG\r\n\x1a\n":
        return "png"
    if data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "webp"
    if data[:6] in (b"GIF87a", b"GIF89a"):
        return "gif"
    if data[4:12] in (b"ftypavif", b"ftypheic"):
        return "avif"
    return None


def thumbnail(base, cookie, room, csrf, path):
    data = open(path, "rb").read()
    name = path.rsplit("/", 1)[-1]
    ctype = "image/png" if name.endswith(".png") else "image/jpeg"
    boundary = "----benchcheck" + uuid.uuid4().hex
    parts = []
    for k, v in (("authenticity_token", csrf), ("message[client_message_id]", uuid.uuid4().hex)):
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="message[attachment]"; filename="{name}"\r\n'
                 f"Content-Type: {ctype}\r\n\r\n".encode() + data + f"\r\n--{boundary}--\r\n".encode())
    headers = {
        "Cookie": cookie, "Content-Type": f"multipart/form-data; boundary={boundary}",
        "Accept": "text/vnd.turbo-stream.html, text/html", "X-CSRF-Token": csrf, "Sec-Fetch-Site": "same-origin",
    }
    out = {"ok": False}
    t0 = time.time()
    status, _, body = request(f"{base}/rooms/{room}/messages", "POST", headers, b"".join(parts))
    out["post_status"] = status
    html = body.decode("utf-8", "replace")
    src = None
    for tag in re.findall(r"<img\b[^>]*>", html):
        if re.search(r'class="[^"]*\bmessage__attachment\b', tag):
            m = re.search(r'\bsrc="([^"]+)"', tag)
            src = m and m.group(1).replace("&amp;", "&")
            break
    if not src:
        out["error"] = f"no <img class=\"message__attachment\"> in the {status} response ({len(body)} bytes)"
        return out
    out["src"] = src
    url, hops = src, []
    for _ in range(6):
        if url.startswith("/"):
            url = base + url
        status, h, data = request(url, headers={"Cookie": cookie})
        hops.append(status)
        loc = next((v for k, v in h.items() if k.lower() == "location"), None)
        if 300 <= status < 400 and loc:
            url = loc
            continue
        break
    out.update(hops=hops, thumb_status=status, thumb_bytes=len(data), thumb_kind=image_kind(data),
               content_type=next((v for k, v in h.items() if k.lower() == "content-type"), None),
               total_ms=round((time.time() - t0) * 1000, 1))
    if status != 200:
        out["error"] = f"thumbnail GET ended with {status} after {hops}"
    elif not out["thumb_kind"]:
        out["error"] = f"thumbnail body is not an image ({out['content_type']}, {len(data)} bytes)"
    elif len(data) >= len(open(path, "rb").read()):
        out["error"] = f"thumbnail ({len(data)} bytes) is not smaller than the original"
    else:
        out["ok"] = True
    return out


PAGE_TAGS = ("div", "a", "img", "form", "input", "button", "template", "script", "link", "meta", "svg",
             "turbo-cable-stream-source", "turbo-frame", "time", "span", "li", "p")


def page(base, cookie, path):
    status, h, body = request(base + path, headers={"Cookie": cookie, "Accept-Encoding": "gzip"})
    enc = next((v for k, v in h.items() if k.lower() == "content-encoding"), None)
    html = gzip.decompress(body) if enc == "gzip" else body
    text = html.decode("utf-8", "replace")
    ids = re.findall(r'\bdata-message-id="(\d+)"', text) or re.findall(r'\bid="message_(\d+)"', text)
    seen, messages = set(), []
    for i in ids:
        if i not in seen:
            seen.add(i)
            messages.append(int(i))
    tags = collections.Counter(t.lower() for t in re.findall(r"<([a-zA-Z][a-zA-Z0-9-]*)", text))
    # Hidden CSRF inputs: Rails-style apps mask the token per form (a distinct, incompressible value in
    # each of the page's forms), others repeat one value; that alone changes the gzip size a lot.
    tokens = [m.group(2) or m.group(3) for m in re.finditer(
        r'<input\b(?=[^>]*\bname="(authenticity_token|_token|_csrf_token)")(?:[^>]*\bvalue="([^"]*)"|[^>]*)[^>]*>()', text)]
    return {"path": path, "status": status, "encoding": enc, "wire_bytes": len(body), "decoded_bytes": len(html),
            "text_bytes": len(re.sub(r"\s+", " ", re.sub(r"<[^>]*>", " ", text)).strip()),
            "messages": len(messages), "message_ids_sha": hashlib.sha256(",".join(map(str, messages)).encode()).hexdigest()[:16],
            "first_last_message": [messages[0], messages[-1]] if messages else None,
            "elements": sum(tags.values()), "tags": {t: tags.get(t, 0) for t in PAGE_TAGS},
            "csrf_inputs": len(tokens), "distinct_csrf_values": len(set(t for t in tokens if t))}


def connect_ro(db):
    con = sqlite3.connect(f"file:{db}?mode=ro", uri=True, timeout=30)
    con.execute("PRAGMA busy_timeout = 30000")
    return con


def writes_mark(db):
    con = connect_ro(db)
    max_id, n = con.execute("SELECT COALESCE(MAX(id), 0), COUNT(*) FROM messages").fetchone()
    con.close()
    return {"max_id": max_id, "messages": n}


def writes_counts(db, max_id):
    con = connect_ro(db)
    q = lambda sql: con.execute(sql, (max_id,)).fetchone()[0]
    out = {
        "new_messages": q("SELECT COUNT(*) FROM messages WHERE id > ?"),
        "with_bench_rich_text": q("SELECT COUNT(*) FROM messages m JOIN action_text_rich_texts r ON r.record_type = 'Message' "
                                  "AND r.record_id = m.id AND r.name = 'body' WHERE m.id > ? AND r.body LIKE '%bench write %'"),
        "in_fts": q("SELECT COUNT(*) FROM messages WHERE id > ? AND id IN (SELECT rowid FROM message_search_index)"),
        "fts_match": q("SELECT COUNT(*) FROM message_search_index WHERE rowid > ? AND message_search_index MATCH '\"bench write\"'"),
    }
    con.close()
    return out


def writes(db, mark, acked, settle_secs=10.0):
    max_id = json.loads(mark)["max_id"] if mark.lstrip().startswith("{") else int(mark)
    acked = int(acked)
    t0 = time.time()
    while True:
        c = writes_counts(db, max_id)
        aligned = c["new_messages"] == c["with_bench_rich_text"] == c["in_fts"] == c["fts_match"] == acked
        if aligned or time.time() - t0 >= float(settle_secs):
            break
        time.sleep(0.25)
    con = connect_ro(db)
    integrity = "; ".join(r[0] for r in con.execute("PRAGMA integrity_check"))
    con.close()
    out = {"acknowledged": acked, **c, "integrity": integrity, "settle_ms": round((time.time() - t0) * 1000), "mark": max_id}
    problems = []
    if acked == 0:
        problems.append("no acknowledged posts")
    if c["new_messages"] != acked:
        problems.append(f"{c['new_messages']} new messages for {acked} acknowledged posts")
    if c["with_bench_rich_text"] != c["new_messages"]:
        problems.append(f"{c['with_bench_rich_text']} of {c['new_messages']} new messages have a 'bench write' rich text body")
    if c["in_fts"] != c["new_messages"]:
        problems.append(f"{c['in_fts']} of {c['new_messages']} new messages are in message_search_index")
    if c["fts_match"] != c["new_messages"]:
        problems.append(f"message_search_index MATCH '\"bench write\"' finds {c['fts_match']} of {c['new_messages']} new messages")
    if integrity != "ok":
        problems.append(f"integrity_check: {integrity[:300]}")
    out.update(ok=not problems, problems=problems)
    return out


def check(r):
    problems = []
    s = r.get("scrape") or {}
    if s:
        if not s.get("csrf") and r.get("csrf_mode") != "sec-fetch-site":
            problems.append("scrape: no csrf-token meta on the room page")
        if not s.get("streams"):
            problems.append("scrape: no <turbo-cable-stream-source> on the room page/sidebar")
        if not s.get("css"):
            problems.append("scrape: no stylesheet link on the room page")
        if s.get("status") != 200:
            problems.append(f"scrape: room page returned {s.get('status')}")
    for h in r.get("http", []):
        where = f"http {h['route']} c={h['conc']}"
        bad = {k: v for k, v in h["statuses"].items() if not 200 <= int(k) < 400}
        if bad:
            problems.append(f"{where}: non-2xx/3xx {bad}")
        if h["errors"]:
            problems.append(f"{where}: {h['errors']} transport errors")
        if h["latency"].get("n", 0) == 0:
            problems.append(f"{where}: no responses")
    for c in r.get("cable", []):
        where = f"cable {c['clients']} clients"
        if c["ready"] != c["clients"] or c.get("failed"):
            problems.append(f"{where}: {c['ready']}/{c['clients']} subscribed, {c.get('failed')} failed")
        lat, tput = c["latency"], c["throughput"]
        if lat["complete"] != lat["messages"]:
            problems.append(f"{where}: paced {lat['complete']}/{lat['messages']} messages reached every client")
        if lat.get("post", {}).get("n", 0) != lat["messages"]:
            problems.append(f"{where}: paced posts {lat.get('post', {}).get('n', 0)}/{lat['messages']} succeeded")
        if tput["posted"] == 0 or tput["complete"] != tput["posted"]:
            problems.append(f"{where}: saturated {tput['complete']}/{tput['posted']} messages reached every client")
    up = r.get("upload") or {}
    for i, u in enumerate(up.get("runs", [])):
        if u.get("error") or not 200 <= u.get("post_status", 0) < 400 or u.get("thumb_status") not in (200, None):
            problems.append(f"upload run {i + 1}: {u}")
    t = r.get("upload_check")
    if t is not None and not t.get("ok"):
        problems.append(f"upload thumbnail: {t.get('error')}")
    w = r.get("writes")
    if w is not None and not w.get("ok"):
        problems.append("writes: " + "; ".join(w.get("problems") or [w.get("error", "check failed")]))
    return {"ok": not problems, "problems": problems}


if __name__ == "__main__":
    if len(sys.argv) == 7 and sys.argv[1] == "thumbnail":
        try:
            print(json.dumps(thumbnail(*sys.argv[2:])))
        except Exception as e:  # recorded, not fatal: the rep is marked invalid
            print(json.dumps({"ok": False, "error": f"{type(e).__name__}: {e}"}))
    elif len(sys.argv) == 3 and sys.argv[1] == "rep":
        r = json.load(open(sys.argv[2]))
        r["validation"] = check(r)
        json.dump(r, open(sys.argv[2], "w"), indent=1)
        v = r["validation"]
        print("valid" if v["ok"] else "INVALID: " + "; ".join(v["problems"]))
    elif len(sys.argv) >= 5 and sys.argv[1] == "page":
        out = {}
        for spec in sys.argv[4:]:
            name, _, path = spec.partition("=")
            try:
                out[name] = page(sys.argv[2], sys.argv[3], path)
            except Exception as e:  # recorded, not fatal
                out[name] = {"path": path, "error": f"{type(e).__name__}: {e}"}
        print(json.dumps(out))
    elif len(sys.argv) == 3 and sys.argv[1] == "pages-summary":
        print("; ".join(f"{k} {v.get('status')} {v.get('messages')} msgs {v.get('elements')} elements {v.get('decoded_bytes')} B ({v.get('wire_bytes')} on the wire)"
                        if "error" not in v else f"{k} {v['error']}" for k, v in json.loads(sys.argv[2]).items()))
    elif len(sys.argv) == 3 and sys.argv[1] == "writes-mark":
        print(json.dumps(writes_mark(sys.argv[2])))
    elif len(sys.argv) in (5, 6) and sys.argv[1] == "writes":
        try:
            print(json.dumps(writes(*sys.argv[2:])))
        except Exception as e:  # recorded, not fatal: the rep is marked invalid
            print(json.dumps({"ok": False, "error": f"{type(e).__name__}: {e}", "problems": [f"{type(e).__name__}: {e}"]}))
    else:
        sys.exit(__doc__)
