#!/usr/bin/env python3
"""Cross-runtime check: Rails (campfire-reference:app) and Symfony (campfire-symfony:app) on ONE
shared storage volume (SQLite DB + Active Storage files).

Phases
  R1  Rails alone:    log in, write the full action suite (tag "rails1").
  S1  Symfony alone:  Rails cookies + Rails meta CSRF token work here; verify R1's data;
                      sign out a Rails-created session; write the suite (tag "symf1").
  R2  Rails alone:    rollback. db:migrate:status, schema unchanged, Symfony cookies + token work,
                      the session signed out on Symfony is dead, verify S1's data; sign out a
                      Symfony-created session; write a small suite (tag "rails2").
  S2  Symfony alone:  the session signed out on Rails is dead; verify R2's data.
  C   Both at once on the same volume: concurrent writes from both, read-after-write across apps,
      fragment cache freshness, sign-out propagation, realtime (each app has its own pub/sub).
  Between phases (no app running): PRAGMA integrity_check / foreign_key_check, FTS consistency.

Run: python3 tools/crossruntime/crossruntime.py [--keep]   (or tools/crossruntime/run.sh)
Results: tools/crossruntime/out/results.json and out/run.log; container logs in out/logs/.
"""

from __future__ import annotations

import json
import re
import sys
import threading
import time
import traceback
import uuid
from html import unescape
from pathlib import Path
from urllib.parse import quote, urlsplit

sys.path.insert(0, str(Path(__file__).parent))
import xrt_docker as D  # noqa: E402
from xrt_http import TURBO_STREAM, Client, Jar, image_kind, png  # noqa: E402
from xrt_ws import CableClient  # noqa: E402

OUT = Path(__file__).parent / "out"
LABELS = json.loads((D.SEED / "labels.json").read_text())
L = LABELS.get
PASSWORD = L("passwords.all")
ROOM_HQ = L("rooms.hq")
ROOM_WATERCOOLER = L("rooms.watercooler")
ROOM_PETS = L("rooms.pets")
ROOM_DESIGNERS = L("rooms.designers")
U = {k.split(".", 1)[1]: v for k, v in LABELS.items() if k.startswith("users.")}
BOT_KEY = L("bot_keys.bender")
JOIN_CODE = L("join_codes.signal")
ERROR_MARKERS = ["We're sorry, but something went wrong", "Internal Server Error", "Whoops",
                 "Stack trace", "Exception", "Uncaught", "Twig\\Error", "Doctrine\\"]

RESULTS: list[dict] = []
MAIN_VOLUME = D.VOLUME
FRESH_VOLUME = MAIN_VOLUME + "-fresh"
LOG = None


def log(msg: str) -> None:
    print(msg, flush=True)
    if LOG:
        LOG.write(msg + "\n")
        LOG.flush()


def check(phase: str, cid: str, desc: str, ok: bool, evidence: str = "", expected_fail: bool = False) -> bool:
    status = "PASS" if ok else ("INFO" if expected_fail else "FAIL")
    RESULTS.append({"phase": phase, "id": cid, "desc": desc, "status": status, "evidence": evidence})
    log(f"[{status}] {phase} {cid}: {desc}" + (f"  -- {evidence}" if evidence else ""))
    return ok


def base(app: str) -> str:
    return D.base_url(app)


def other(app: str) -> str:
    return "symfony" if app == "rails" else "rails"


def page_ok(r) -> tuple[bool, str]:
    t = r.text
    bad = [m for m in ERROR_MARKERS if m in t]
    return (r.status == 200 and not bad and "<title>Sign in</title>" not in t), f"{r.short()} {len(r.body)}B" + (f" markers={bad}" if bad else "")


def login(app: str, email: str, password: str = None) -> tuple[Client, object]:
    c = Client(base(app), label=f"{app}:{email}")
    r = c.get("/session/new")
    token = r.csrf()
    r = c.post_form("/session", {"email_address": email, "password": password or PASSWORD}, csrf=token)
    return c, r


def signed_in(c: Client, path: str = f"/rooms/{ROOM_HQ}") -> tuple[bool, str]:
    r = c.get(path)
    ok, ev = page_ok(r)
    return ok, ev


def csrf_for(c: Client, path: str = f"/rooms/{ROOM_HQ}") -> str:
    r = c.get(path)
    return r.csrf()


def message_ids(text: str) -> list[int]:
    return [int(x) for x in re.findall(r'data-message-id="(\d+)"', text)]


def mention_sgid(c: Client, name: str) -> str | None:
    r = c.get(f"/autocompletable/users.json?query={quote(name)}", headers={"Accept": "application/json"})
    try:
        for u in json.loads(r.text):
            if u["name"].lower().startswith(name.lower()):
                return u["sgid"]
    except Exception:
        return None
    return None


def post_message(c: Client, room: int, body: str, csrf: str, attachment: tuple | None = None):
    cmid = str(uuid.uuid4())
    if attachment:
        r = c.post_multipart(f"/rooms/{room}/messages", [("message[client_message_id]", cmid)],
                             [("message[attachment]", *attachment)], csrf=csrf, accept=TURBO_STREAM)
    else:
        r = c.post_form(f"/rooms/{room}/messages", [("message[body]", body), ("message[client_message_id]", cmid)],
                        csrf=csrf, accept=TURBO_STREAM)
    ids = message_ids(r.text)
    return r, (ids[0] if ids else None), cmid


def search(c: Client, q: str, csrf: str):
    return c.post_form("/searches", {"q": q}, csrf=csrf, follow=True)


def sidebar_unread(c: Client, room_id: int) -> tuple[bool | None, str]:
    r = c.get("/users/me/sidebar")
    m = re.search(r'<a[^>]*id="list_rooms_\w+_%d"[^>]*>' % room_id, r.text) or re.search(
        r'<a[^>]*id="[^"]*_%d"[^>]*class="[^"]*room[^"]*"[^>]*>' % room_id, r.text)
    if not m:
        return None, f"{r.short()} room {room_id} link not found in sidebar"
    tag = m.group(0)
    cls = re.search(r'class="([^"]*)"', tag)
    return ("unread" in (cls.group(1).split() if cls else [])), tag[:200]


def fetch_image(c: Client, url: str) -> tuple[bool, str]:
    url = unescape(url)
    r = c.get(url, follow=True)
    kind = image_kind(r.body)
    return (r.status == 200 and kind in ("webp", "png", "jpeg", "svg")), f"GET {url[:90]}... -> {r.status} {r.header('Content-Type')} {kind} {len(r.body)}B"


def user_lookup(c: Client, name: str) -> dict | None:
    r = c.get(f"/autocompletable/users.json?query={quote(name)}", headers={"Accept": "application/json"})
    try:
        for u in json.loads(r.text):
            if unescape(u["name"]) == name:
                return u
    except Exception:
        return None
    return None


# ---------------------------------------------------------------------------------------------
# Write suite: everything the task lists, done through the HTTP API of `app` as a browser would.
# ---------------------------------------------------------------------------------------------

def write_suite(phase: str, app: str, tag: str, full: bool = True) -> dict:
    A: dict = {"app": app, "tag": tag}
    c, r = login(app, L("emails.david"))
    check(phase, "write.login", f"david logs in on {app}", r.status == 302 and "session_token" in c.jar.cookies, r.short())
    csrf = csrf_for(c)
    A["david_jar"] = c.jar.copy()

    # 1. text message with a mention of Jason (in HQ, where jason/jz/kevin also are -> unread for them)
    sgid = mention_sgid(c, "Jason")
    check(phase, "write.sgid", f"{app} autocompletable returns a mention sgid for Jason", bool(sgid), (sgid or "")[:40])
    body = (f'<div>Hello <action-text-attachment sgid="{sgid}" content-type="application/vnd.campfire.mention">'
            f'</action-text-attachment> word{tag}mention from {app}</div>')
    r, mid, _ = post_message(c, ROOM_HQ, body, csrf)
    check(phase, "write.mention", f"{app}: post message with mention", r.status == 200 and bool(mid), f"{r.short()} id={mid}")
    A["mention_msg"] = mid

    # 2. image upload
    img = png(1600, 1000, seed=len(tag))
    r, iid, _ = post_message(c, ROOM_HQ, "", csrf, attachment=(f"xrt-{tag}.png", "image/png", img))
    check(phase, "write.image", f"{app}: post image attachment", r.status == 200 and bool(iid), f"{r.short()} id={iid}")
    A["image_msg"] = iid

    # 3. edit
    r, eid, _ = post_message(c, ROOM_HQ, f"<div>word{tag}before edit</div>", csrf)
    r2 = c.post_form(f"/rooms/{ROOM_HQ}/messages/{eid}", [("message[body]", f"<div>word{tag}after edit</div>")],
                     csrf=csrf, method_override="patch")
    check(phase, "write.edit", f"{app}: edit message", r.status == 200 and r2.status in (302, 303), f"create {r.short()}, update {r2.short()}")
    A["edited_msg"] = eid

    # 4. delete
    r, did, _ = post_message(c, ROOM_HQ, f"<div>word{tag}deleted soon</div>", csrf)
    r2 = c.post_form(f"/rooms/{ROOM_HQ}/messages/{did}", [], csrf=csrf, method_override="delete", accept=TURBO_STREAM)
    check(phase, "write.delete", f"{app}: delete message", r.status == 200 and r2.status == 200, f"create {r.short()}, destroy {r2.short()}")
    A["deleted_msg"] = did

    # 5. boost (on the mention message)
    boost = f"boost{tag}"[:16]
    r = c.post_form(f"/messages/{mid}/boosts", [("boost[content]", boost)], csrf=csrf)
    check(phase, "write.boost", f"{app}: boost a message", r.status in (302, 303), r.short())
    A["boost"] = boost

    # 6. rooms: open, closed, direct
    rooms = {}
    r = c.post_form("/rooms/opens", [("room[name]", f"XRT open {tag}")], csrf=csrf)
    rooms["open"] = int(m.group(1)) if (m := re.search(r"/rooms/(\d+)", r.location or "")) else None
    r2 = c.post_form("/rooms/closeds", [("room[name]", f"XRT closed {tag}"), ("user_ids[]", U["david"]), ("user_ids[]", U["kevin"])], csrf=csrf)
    rooms["closed"] = int(m.group(1)) if (m := re.search(r"/rooms/(\d+)", r2.location or "")) else None
    peer = U["jz"] if tag.startswith("rails") else U["loner"]
    if tag == "rails2":
        peer = U["kevin"]
        r3 = c.post_form("/rooms/directs", [("user_ids[]", U["jz"]), ("user_ids[]", U["loner"])], csrf=csrf)
    else:
        r3 = c.post_form("/rooms/directs", [("user_ids[]", peer)], csrf=csrf)
    rooms["direct"] = int(m.group(1)) if (m := re.search(r"/rooms/(\d+)", r3.location or "")) else None
    check(phase, "write.rooms", f"{app}: create open/closed/direct rooms", all(rooms.values()),
          f"open {r.short()} closed {r2.short()} direct {r3.short()}")
    A["rooms"] = rooms
    if rooms["open"]:
        r, oid, _ = post_message(c, rooms["open"], f"<div>word{tag}openroom first</div>", csrf)
        A["open_room_msg"] = oid

    # 7. involvement change (david in watercooler -> everything / pets -> nothing)
    inv_room, inv = (ROOM_WATERCOOLER, "everything") if tag.startswith("rails") else (ROOM_PETS, "nothing")
    if tag == "rails2":
        inv_room, inv = ROOM_DESIGNERS, "invisible"
    r = c.post_form(f"/rooms/{inv_room}/involvement", [("involvement", inv)], csrf=csrf, method_override="put")
    check(phase, "write.involvement", f"{app}: set involvement {inv} in room {inv_room}", r.status in (302, 303), r.short())
    A["involvement"] = (inv_room, inv)

    # 8. bot API post (Bender in watercooler)
    bc = Client(base(app))
    r = bc.request("POST", f"/rooms/{ROOM_WATERCOOLER}/{BOT_KEY}/messages", body=f"word{tag}bot says hi".encode(),
                   headers={"Content-Type": "text/plain", "Accept": "*/*"})
    bot_id = int(m.group(1)) if (m := re.search(r"/messages/(\d+)", r.location or "")) else None
    check(phase, "write.bot", f"{app}: bot API post", r.status == 201 and bool(bot_id), f"{r.short()}")
    A["bot_msg"] = bot_id

    if full:
        # 9. join via join code -> new user; then profile change + avatar upload as that user
        j = Client(base(app))
        r = j.get(f"/join/{JOIN_CODE}")
        jt = r.csrf()
        email, pw, name = f"xrt-{tag}@example.com", f"pw-{tag}-123456", f"Xrt {tag} Joiner"
        r = j.post_multipart(f"/join/{JOIN_CODE}", [("user[name]", name), ("user[email_address]", email), ("user[password]", pw)], [], csrf=jt)
        check(phase, "write.join", f"{app}: join via join code", r.status in (302, 303) and "session_token" in j.jar.cookies, r.short())
        A["joiner"] = {"email": email, "password": pw, "name": name}
        jt = csrf_for(j, "/users/me/profile")
        newname = f"Xrt {tag} Renamed"
        avatar = png(256, 256, seed=len(tag) + 11)
        r = j.post_multipart("/users/me/profile", [("user[name]", newname), ("user[bio]", f"bio{tag}")],
                             [("user[avatar]", f"avatar-{tag}.png", "image/png", avatar)], csrf=jt, method_override="patch")
        check(phase, "write.profile", f"{app}: profile name/bio + avatar upload", r.status in (302, 303), r.short())
        A["joiner"]["renamed"] = newname
        A["joiner"]["bio"] = f"bio{tag}"
        u = user_lookup(c, newname)
        A["joiner"]["id"] = u and u["value"]
        A["joiner"]["avatar_url"] = u and u["avatar_url"]
        if u:
            ok, ev = fetch_image(c, u["avatar_url"])
            check(phase, "write.avatar.self", f"{app}: serves the avatar it just stored", ok, ev)

    # time for async jobs (blob analysis, variants) to settle before the app is stopped
    time.sleep(3)
    return A


# ---------------------------------------------------------------------------------------------
# Verify suite: on `app`, check data written by the other runtime.
# ---------------------------------------------------------------------------------------------

def verify_suite(phase: str, app: str, A: dict, jason_client: Client | None = None) -> None:
    tag, src = A["tag"], A["app"]
    p = f"{src}->{app}"
    # unread state, before anything marks the room read
    if jason_client is not None:
        unread, ev = sidebar_unread(jason_client, ROOM_HQ)
        check(phase, "verify.unread", f"[{p}] Jason sees HQ unread after david's {src} posts", unread is True, ev)

    c = Client(base(app), jar=A["david_jar"].copy())  # the session created on the other runtime
    r = c.get(f"/rooms/{ROOM_HQ}")
    ok, ev = page_ok(r)
    check(phase, "verify.room", f"[{p}] HQ page renders with the other runtime's session", ok, ev)
    csrf = r.csrf()
    ids = set(message_ids(r.text))
    t = r.text
    block = message_block(t, A["mention_msg"])
    pres = presentation(block)
    check(phase, "verify.mention", f"[{p}] mention message shown, mention rendered as Jason's chip",
          A["mention_msg"] in ids and f"word{tag}mention" in pres and '<span class="mention"><a title="Jason"' in pres and f'href="/users/{U["jason"]}"' in pres,
          pres[pres.find('<span class="mention"'):][:160].replace("\n", " "))
    check(phase, "verify.boost", f"[{p}] boost shown", A["boost"] in block, A["boost"])
    check(phase, "verify.edit", f"[{p}] edited body shown", f"word{tag}after edit" in t and f"word{tag}before edit" not in t, "")
    check(phase, "verify.delete", f"[{p}] deleted message absent", A["deleted_msg"] not in ids and f"word{tag}deleted" not in t, "")

    # image thumbnail served by this runtime
    iblock = message_block(t, A["image_msg"])
    m = re.search(r'<img[^>]+src="([^"]+)"[^>]*class="message__attachment', iblock) or re.search(r'<img[^>]+class="message__attachment[^"]*"[^>]*src="([^"]+)"', iblock)
    if check(phase, "verify.image.tag", f"[{p}] image message renders a thumbnail <img>", bool(m), (m.group(1)[:100] if m else iblock[:300])):
        ok, ev = fetch_image(c, m.group(1))
        check(phase, "verify.image.thumb", f"[{p}] thumbnail served", ok, ev)
    m = re.search(r'href="([^"]*/rails/active_storage/blobs/[^"]+)"', iblock)
    if m:
        ok, ev = fetch_image(c, m.group(1))
        check(phase, "verify.image.blob", f"[{p}] original blob (lightbox link) served", ok, ev)

    # search
    for word, expect, mid in [(f"word{tag}mention", True, A["mention_msg"]), (f"word{tag}after", True, A["edited_msg"]),
                              (f"word{tag}before", False, A["edited_msg"]), (f"word{tag}deleted", False, A["deleted_msg"]),
                              (f"word{tag}bot", True, A["bot_msg"])]:
        r = search(c, word, csrf)
        found = mid in message_ids(r.text)
        check(phase, f"verify.search.{word}", f"[{p}] search '{word}' {'finds' if expect else 'does not find'} message {mid}",
              r.status == 200 and found == expect, f"{r.short()} found={found}")

    # rooms
    for kind, rid in A["rooms"].items():
        if not rid:
            continue
        r = c.get(f"/rooms/{rid}")
        ok, ev = page_ok(r)
        check(phase, f"verify.room.{kind}", f"[{p}] {kind} room {rid} renders", ok, ev)
        if kind == "open" and A.get("open_room_msg"):
            check(phase, "verify.room.open.msg", f"[{p}] open room shows its message", A["open_room_msg"] in message_ids(r.text), "")
        r = c.get(f"/rooms/{kind}s/{rid}/edit")
        ok, ev = page_ok(r)
        check(phase, f"verify.room.{kind}.edit", f"[{p}] {kind} room edit page renders", ok, ev)
    sb = c.get("/users/me/sidebar")
    check(phase, "verify.sidebar", f"[{p}] sidebar lists the new rooms", sb.status == 200 and all(f"_{rid}\"" in sb.text for rid in A["rooms"].values() if rid), sb.short())

    inv_room, inv = A["involvement"]
    r = c.get(f"/rooms/{inv_room}/involvement")
    check(phase, "verify.involvement", f"[{p}] involvement '{inv}' shown for room {inv_room}", r.status == 200 and f'class="btn {inv}"' in r.text,
          (re.search(r'class="btn [a-z]+"', r.text) or [r.short()])[0])

    # bot message
    r = c.get(f"/rooms/{ROOM_WATERCOOLER}")
    check(phase, "verify.bot", f"[{p}] bot message shown in watercooler", A["bot_msg"] in message_ids(r.text) and f"word{tag}bot" in r.text, r.short())
    r = Client(base(app)).request("GET", f"/rooms/{ROOM_WATERCOOLER}/{BOT_KEY}/messages", headers={"Accept": "application/json"})
    check(phase, "verify.bot.api", f"[{p}] bot API index lists it", r.status == 200 and str(A["bot_msg"]) in r.text, r.short())

    # joined user: password works on this runtime; profile + avatar
    J = A.get("joiner")
    if J:
        jc, r = login(app, J["email"], J["password"])
        check(phase, "verify.join.login", f"[{p}] user created by join on {src} logs in here with its password", r.status == 302 and "session_token" in jc.jar.cookies, r.short())
        if J.get("id"):
            r = c.get(f"/users/{J['id']}")
            ok, ev = page_ok(r)
            check(phase, "verify.profile.page", f"[{p}] joined user's profile page shows new name + bio", ok and J["renamed"] in r.text and J["bio"] in r.text, ev)
            u = user_lookup(c, J["renamed"])
            if check(phase, "verify.avatar.url", f"[{p}] avatar url built for renamed user", bool(u), str(u and u["avatar_url"])):
                same = path_of(u["avatar_url"]) == path_of(J["avatar_url"])
                ok, ev = fetch_image(c, u["avatar_url"])
                check(phase, "verify.avatar", f"[{p}] uploaded avatar served (webp)", ok and "webp" in ev, ev + f" same_url_as_writer={same}")
            r = jc.get("/users/me/profile")
            ok, ev = page_ok(r)
            check(phase, "verify.profile.self", f"[{p}] joined user's own profile page renders", ok, ev)

    # misc pages
    for path in ["/", "/users/me/profile", f"/searches", "/account/edit", "/account/bots", f"/messages/{A['mention_msg']}/boosts"]:
        r = c.get(path, follow=True)
        ok, ev = page_ok(r)
        check(phase, f"verify.page{path}", f"[{p}] GET {path}", ok, ev)


def path_of(url: str) -> str:
    u = urlsplit(unescape(url))
    return u.path + "?" + u.query


def presentation(block: str) -> str:
    m = re.search(r'<div id="presentation_message_.*?</div>\s*</div>', block, re.S)
    return m.group(0) if m else ""


def message_block(text: str, mid: int | None) -> str:
    if not mid:
        return ""
    i = text.find(f'data-message-id="{mid}"')
    if i < 0:
        return ""
    j = text.find('data-message-id="', i + 20)
    return text[i: j if j > 0 else i + 20000]


# ---------------------------------------------------------------------------------------------
# DB checks (no app running)
# ---------------------------------------------------------------------------------------------

SEED_FK = None
SEED_META = ""


def db_checks(phase: str, seed_schema: str, after_crash: bool = False) -> None:
    global SEED_FK
    out = D.sql("pragma integrity_check;")
    check(phase, "db.integrity", "PRAGMA integrity_check", out == "ok", out[:200])
    out = D.sql("pragma foreign_key_check;")
    if SEED_FK is None:
        SEED_FK = out
        log(f"(seed fixture foreign_key_check baseline: {out!r} -- deliberate broken fixture rows)")
    check(phase, "db.fk", "PRAGMA foreign_key_check: no violations beyond the seed's deliberate fixture rows", out == SEED_FK, out[:200])
    out = D.sql("select count(*) from active_storage_attachments a join active_storage_variant_records v on v.blob_id=a.blob_id "
                "where a.record_type='Message' and a.blob_id > 14 group by a.blob_id having count(*) > 1;")
    check(phase, "db.variants", "message images have exactly one :thumb variant record (both runtimes agree on the variation digest)", out == "", out[:200])
    out = D.sql("select count(*) from users u join active_storage_attachments a on a.record_type='User' and a.record_id=u.id and a.name='avatar' "
                "join active_storage_variant_records v on v.blob_id=a.blob_id where a.blob_id > 14 group by a.blob_id having count(*) > 1;")
    check(phase, "db.variants.avatar", "avatars have exactly one :square variant record", out == "", out[:200])
    out = D.sql("select b.id, a.record_type, b.content_type, b.metadata from active_storage_blobs b join active_storage_attachments a on a.blob_id=b.id "
                "where b.id > 14 and a.record_type != 'ActiveStorage::VariantRecord' and b.metadata not like '%\"analyzed\":true%';")
    check(phase, "db.blobs.analyzed", "every uploaded (non-variant) blob analyzed (metadata analyzed:true); seed blobs 1-14 excluded", out == "", out[:300])
    out = D.sql("select (select count(*) from messages), (select count(*) from message_search_index);")
    a, b = out.split("|")
    # After a SIGKILL a message can be committed without its FTS row: Rails indexes in after_create_commit
    # (a second transaction) and crash_probe.py shows Rails itself loses rows that way. Reported as INFO there.
    check(phase, "fts.count", "FTS rows == messages", a == b, f"messages={a} fts={b}", expected_fail=after_crash)
    out = D.sql("select count(*) from messages where id not in (select rowid from message_search_index);"
                "select count(*) from message_search_index where rowid not in (select id from messages);")
    mo = out.split()
    if after_crash and mo[1] == "0" and mo[0] != "0":
        check(phase, "fts.orphans", "no orphan FTS rows; unindexed messages from the SIGKILL window (reference after_create_commit design)", False, f"missing,orphans={mo}", expected_fail=True)
    else:
        check(phase, "fts.orphans", "every message indexed, no orphan FTS rows", mo == ["0", "0"], f"missing,orphans={mo}")
    out = D.sql("insert into message_search_index(message_search_index) values('integrity-check');")
    check(phase, "fts.integrity", "FTS5 'integrity-check'", out == "", out[:200] or "ok")
    schema = D.sql("select type, name, tbl_name, sql from sqlite_master where name not like 'sqlite_%' order by name;")
    check(phase, "db.schema", "sqlite_master identical to the seed (no added/changed tables)", schema == seed_schema,
          "" if schema == seed_schema else diff(seed_schema, schema))
    out = D.sql("select count(*) from schema_migrations; select value from ar_internal_metadata where key='environment';")
    check(phase, "db.migrations", "schema_migrations/ar_internal_metadata intact", out.split()[-1] == "production", out.replace("\n", " "))
    out = D.sql("pragma journal_mode;")
    check(phase, "db.wal", "journal_mode still WAL", out == "wal", out)
    out = D.sql("select count(*) from active_storage_blobs b where not exists (select 1 from active_storage_attachments a where a.blob_id=b.id);")
    check(phase, "db.blobs", "no unattached blobs", out == "0", out, expected_fail=True)
    log("    push_subscriptions=" + D.sql("select count(*) from push_subscriptions;") + " sessions=" + D.sql("select count(*) from sessions;")
        + " users=" + D.sql("select count(*) from users;") + " rooms=" + D.sql("select count(*) from rooms;") + " boosts=" + D.sql("select count(*) from boosts;"))
    files = D.shell("cd /rails/storage/files && find . -type f -not -name '.*' | wc -l")
    blobs = D.sql("select count(*) from active_storage_blobs;")
    check(phase, "storage.files", "every blob has a file on disk", missing_blob_files() == [], f"blobs={blobs} files={files.strip()} missing={missing_blob_files()[:5]}")


def missing_blob_files() -> list[str]:
    keys = D.sql("select key from active_storage_blobs;").split()
    script = " ".join(f"{k[:2]}/{k[2:4]}/{k}" for k in keys)
    out = D.shell(f"cd /rails/storage/files && for f in {script}; do [ -f \"$f\" ] || echo $f; done")
    return [x for x in out.split() if x]


def diff(a: str, b: str) -> str:
    import difflib
    return " | ".join(l for l in difflib.unified_diff(a.splitlines(), b.splitlines(), lineterm="", n=0) if l[:1] in "+-" and l[:3] not in ("+++", "---"))[:1500]


# ---------------------------------------------------------------------------------------------
# Session checks
# ---------------------------------------------------------------------------------------------

def session_cross(phase: str, app: str, jar: Jar, token: str, who: str) -> None:
    src = other(app)
    c = Client(base(app), jar=jar.copy())
    ok, ev = signed_in(c)
    check(phase, "session.page", f"{src}-created session ({who}) authenticates on {app}", ok, ev)
    r = c.get("/users/me/profile")
    ok, ev = page_ok(r)
    check(phase, "session.profile", f"{src} session: /users/me/profile on {app} is {who}'s", ok and f'value="{L("emails." + who)}"' in r.text, ev)
    r, mid, _ = post_message(c, ROOM_HQ, f"<div>csrf{src}to{app} with {src} meta token</div>", token)
    check(phase, "session.csrf", f"CSRF-protected POST on {app} with {src}'s meta csrf-token", r.status == 200 and bool(mid), f"{r.short()} id={mid}")
    r = c.post_form("/searches", {"q": "hello"}, csrf=token)
    check(phase, "session.csrf2", f"second POST (searches#create) on {app} with {src}'s token", r.status in (302, 303), r.short())
    r, _, _ = post_message(Client(base(app), jar=jar.copy()), ROOM_HQ, "<div>forged</div>", "bogus-token")
    check(phase, "session.csrf.neg", f"{app} rejects a bogus CSRF token (422)", r.status == 422, r.short())
    r = Client(base(app)).get(f"/rooms/{ROOM_HQ}")
    check(phase, "session.neg", f"{app} without cookies redirects to sign in", r.status == 302 and "/session/new" in (r.location or ""), r.short())


def sign_out(phase: str, app: str, jar: Jar, who: str) -> None:
    c = Client(base(app), jar=jar.copy())
    token = csrf_for(c)
    r = c.post_form("/session", [], csrf=token, method_override="delete")
    check(phase, "session.signout", f"sign out {who}'s {other(app)}-created session on {app}", r.status in (302, 303) and "session_token" not in c.jar.cookies, f"{r.short()} cookies={sorted(c.jar.cookies)}")
    r = Client(base(app), jar=jar.copy()).get(f"/rooms/{ROOM_HQ}")
    check(phase, "session.signout.local", f"old cookie dead on {app} itself", r.status == 302 and "/session/new" in (r.location or ""), r.short())


def session_dead(phase: str, app: str, jar: Jar, who: str, where: str) -> None:
    r = Client(base(app), jar=jar.copy()).get(f"/rooms/{ROOM_HQ}")
    check(phase, "session.signout.cross", f"{who}'s session signed out on {where} is rejected on {app}", r.status == 302 and "/session/new" in (r.location or ""), r.short())


# ---------------------------------------------------------------------------------------------

def app_log_errors(phase: str, app: str) -> None:
    text = D.logs(app)
    (OUT / "logs").mkdir(parents=True, exist_ok=True)
    (OUT / "logs" / f"{phase}-{app}.log").write_text(text)
    pats = [r"\bFATAL\b", r"\bERROR\b", r"Error -- ", r"Completed 5\d\d", r'"status":5\d\d', r"PendingMigration",
            r"database is locked", r"SQLITE_BUSY", r"CRITICAL", r"Uncaught", r"\bE, \["]
    pats.append(r"^\[[0-9a-f-]{36}\] [A-Z][\w:]+ \(")  # Rails exception class line of a failed request
    # Expected noise, counted separately: Thruster's /up 502 while Puma boots; the deliberate bogus-CSRF
    # negative test (Rails logs an empty "ERROR -- : [req]" header + the exception); the seed's fake push
    # subscription (invalid keys); Rails' PushMessageJob for a message the suite deleted before the job ran.
    ignore = [r'"path":"/up","status":502', r"InvalidAuthenticityToken", r"Can't verify CSRF token", r"ERROR -- : \[[0-9a-f-]{36}\]\s*$",
              r"WebPush", r"DeserializationError", r"Couldn't find Message"]
    lines = text.splitlines()
    hits = [l for l in lines if any(re.search(p, l) for p in pats) and not any(re.search(i, l) for i in ignore)]
    noise = [l for l in lines if any(re.search(p, l) for p in pats) and any(re.search(i, l) for i in ignore)]
    if noise:
        log(f"    ({app} log: {len(noise)} expected-noise lines ignored, e.g. {noise[0][:160]!r})")
    check(phase, f"logs.{app}", f"{app} container log has no errors/5xx/lock errors", not hits, " || ".join(h[:300] for h in hits[:6]))


def stop_app(phase: str, app: str) -> None:
    app_log_errors(phase, app)
    D.stop(app)


def start_app(phase: str, app: str) -> None:
    t = D.start(app)
    log(f"--- {app} up in {t:.1f}s")


def main() -> int:
    global LOG
    keep = "--keep" in sys.argv
    OUT.mkdir(exist_ok=True)
    LOG = open(OUT / "run.log", "w")
    try:
        log(f"seeding volume {D.VOLUME}")
        D.cleanup()
        D.seed()
        seed_schema = D.sql("select type, name, tbl_name, sql from sqlite_master where name not like 'sqlite_%' order by name;")
        global SEED_META
        SEED_META = D.sql("select count(*) from schema_migrations; select key || '=' || value from ar_internal_metadata order by key;")
        db_checks("seed", seed_schema)

        # ---------------- R1: Rails alone
        ph = "R1"
        log(f"===== {ph}: Rails alone"); start_app(ph, "rails")
        rails_david, r = login("rails", L("emails.david"))
        check(ph, "login", "david logs in on Rails", r.status == 302, r.short())
        rails_david_token = csrf_for(rails_david)
        rails_jason, r = login("rails", L("emails.jason"))
        check(ph, "login.jason", "jason logs in on Rails", r.status == 302, r.short())
        rails_kevin, r = login("rails", L("emails.kevin"))
        A_R1 = write_suite(ph, "rails", "rails1")
        stop_app(ph, "rails")
        db_checks(ph, seed_schema)

        # ---------------- S1: Symfony alone
        ph = "S1"
        log(f"===== {ph}: Symfony alone"); start_app(ph, "symfony")
        session_cross(ph, "symfony", rails_david.jar, rails_david_token, "david")
        verify_suite(ph, "symfony", A_R1, jason_client=Client(base("symfony"), jar=rails_jason.jar.copy()))
        jason_before_signout = rails_jason.jar.copy()
        sign_out(ph, "symfony", rails_jason.jar, "jason")
        symf_david, r = login("symfony", L("emails.david"))
        symf_david_token = csrf_for(symf_david)
        symf_kevin, r = login("symfony", L("emails.kevin"))
        symf_jason, r = login("symfony", L("emails.jason"))
        A_S1 = write_suite(ph, "symfony", "symf1")
        stop_app(ph, "symfony")
        db_checks(ph, seed_schema)

        # ---------------- R2: Rails again (rollback)
        ph = "R2"
        log(f"===== {ph}: Rails again (rollback)"); start_app(ph, "rails")
        st = D.exec_("rails", "bin/rails", "db:migrate:status")
        out = st.stdout + st.stderr
        downs = [l for l in out.splitlines() if l.strip().startswith("down") or "NO FILE" in l]
        check(ph, "migrate.status", "bin/rails db:migrate:status: all up, no NO FILE", st.returncode == 0 and not downs and " up " in out,
              f"rc={st.returncode} ups={out.count(' up ')} downs={downs[:3]}")
        (OUT / "logs").mkdir(parents=True, exist_ok=True)
        (OUT / "logs" / "R2-migrate-status.txt").write_text(out)
        st = D.exec_("rails", "bin/rails", "runner", "ActiveRecord::Migration.check_all_pending!; puts :ok")
        check(ph, "migrate.pending", "ActiveRecord::Migration.check_all_pending! passes", "ok" in st.stdout, (st.stdout + st.stderr).strip()[-300:])
        session_cross(ph, "rails", symf_david.jar, symf_david_token, "david")
        session_dead(ph, "rails", jason_before_signout, "jason", "symfony")
        verify_suite(ph, "rails", A_S1, jason_client=Client(base("rails"), jar=symf_jason.jar.copy()))
        kevin_before_signout = symf_kevin.jar.copy()
        sign_out(ph, "rails", symf_kevin.jar, "kevin")
        # the Rails jar got rewritten by Symfony in S1 (cookies Symfony re-issued) — still good on Rails?
        ok, ev = signed_in(Client(base("rails"), jar=rails_david.jar.copy()))
        check(ph, "session.roundtrip", "Rails-created session whose cookies were re-issued by Symfony still works on Rails", ok, ev)
        A_R2 = write_suite(ph, "rails", "rails2", full=True)
        stop_app(ph, "rails")
        db_checks(ph, seed_schema)

        # ---------------- S2: Symfony again
        ph = "S2"
        log(f"===== {ph}: Symfony again"); start_app(ph, "symfony")
        session_dead(ph, "symfony", kevin_before_signout, "kevin", "rails")
        verify_suite(ph, "symfony", A_R2, jason_client=Client(base("symfony"), jar=symf_jason.jar.copy()))
        stop_app(ph, "symfony")
        db_checks(ph, seed_schema)

        # ---------------- C: both concurrently
        ph = "C"
        log(f"===== {ph}: both apps on the same volume at once")
        start_app(ph, "rails"); start_app(ph, "symfony")
        concurrent_phase(ph, rails_david.jar, symf_david.jar, [A_R1, A_S1, A_R2])
        stop_app(ph, "symfony"); stop_app(ph, "rails")
        db_checks(ph, seed_schema)

        # ---------------- K: crash takeover — SIGKILL one runtime mid-burst, the other takes over the WAL
        for victim in ("symfony", "rails"):
            crash_phase(f"K-{victim}", victim, rails_david.jar, seed_schema)

        # ---------------- F: fresh installs — one runtime creates the DB from nothing, the other takes over
        for first in ("symfony", "rails"):
            fresh_install_phase(f"F-{first}", first, seed_schema)

        # ---------------- final: Rails boots once more on the final state, migrate status clean
        ph = "R3"
        D.VOLUME = MAIN_VOLUME
        start_app(ph, "rails")
        st = D.exec_("rails", "bin/rails", "db:migrate:status")
        out = st.stdout + st.stderr
        check(ph, "migrate.status", "final db:migrate:status clean", st.returncode == 0 and "down" not in out and "NO FILE" not in out, f"rc={st.returncode}")
        ok, ev = signed_in(Client(base("rails"), jar=symf_david.jar.copy()))
        check(ph, "session.final", "Symfony-created session still valid on Rails at the end", ok, ev)
        stop_app(ph, "rails")
    except Exception:
        log("ABORTED:\n" + traceback.format_exc())
        RESULTS.append({"phase": "-", "id": "abort", "desc": "script aborted", "status": "FAIL", "evidence": traceback.format_exc()[-1500:]})
    finally:
        for app in D.APPS:
            if D.is_running(app):
                try:
                    app_log_errors("final", app)
                except Exception:
                    pass
        D.VOLUME = FRESH_VOLUME
        D.cleanup(remove_volume=True)
        D.VOLUME = MAIN_VOLUME
        D.cleanup(remove_volume=not keep)
        (OUT / "results.json").write_text(json.dumps(RESULTS, indent=1))
        fails = [r for r in RESULTS if r["status"] == "FAIL"]
        log(f"\n{len(RESULTS)} checks, {len(fails)} FAIL, {sum(r['status'] == 'INFO' for r in RESULTS)} INFO")
        for f in fails:
            log(f"  FAIL {f['phase']} {f['id']}: {f['desc']} -- {f['evidence'][:300]}")
        LOG.close()
    return 1 if fails else 0


# ---------------------------------------------------------------------------------------------
# Concurrent phase
# ---------------------------------------------------------------------------------------------

def concurrent_phase(ph: str, rails_jar: Jar, symf_jar: Jar, suites: list[dict]) -> None:
    rc = {"rails": Client(base("rails"), jar=rails_jar.copy()), "symfony": Client(base("symfony"), jar=symf_jar.copy())}
    tok = {a: csrf_for(c) for a, c in rc.items()}
    check(ph, "both.up", "both apps serve the same session concurrently", all(tok.values()), "")

    # read-after-write across apps
    for a in ("rails", "symfony"):
        b = other(a)
        r, mid, _ = post_message(rc[a], ROOM_HQ, f"<div>wordraw{a} immediate</div>", tok[a])
        r2 = rc[b].get(f"/rooms/{ROOM_HQ}")
        check(ph, f"raw.{a}", f"message posted on {a} visible on {b} immediately", mid in message_ids(r2.text), f"id={mid} {r2.short()}")
        r3 = search(rc[b], f"wordraw{a}", tok[b])
        check(ph, f"raw.search.{a}", f"{b} search finds {a}'s message immediately", mid in message_ids(r3.text), r3.short())

    # fragment caches: warm both, then mutate on one and read on the other
    r, mid, _ = post_message(rc["rails"], ROOM_HQ, "<div>wordcache original</div>", tok["rails"])
    for a in rc:
        rc[a].get(f"/rooms/{ROOM_HQ}")
    for a in ("symfony", "rails"):
        b = other(a)
        rc[b].get(f"/rooms/{ROOM_HQ}")  # warm b's cache with the current version
        newtxt = f"wordcache edited on {a}"
        r = rc[a].post_form(f"/rooms/{ROOM_HQ}/messages/{mid}", [("message[body]", f"<div>{newtxt}</div>")], csrf=tok[a], method_override="patch")
        page = rc[b].get(f"/rooms/{ROOM_HQ}").text
        check(ph, f"cache.edit.{a}", f"edit on {a} visible on {b} (no stale fragment cache)", newtxt in message_block(page, mid), r.short())
        page = rc[b].get(f"/rooms/{ROOM_HQ}/messages?after={mid - 1}", headers={"Accept": "text/html"}).text
        check(ph, f"cache.edit.{a}.index", f"edit on {a} visible in {b}'s messages#index", newtxt in page, "")
        btxt = f"b{a}"
        r = rc[a].post_form(f"/messages/{mid}/boosts", [("boost[content]", btxt)], csrf=tok[a])
        page = rc[b].get(f"/rooms/{ROOM_HQ}").text
        check(ph, f"cache.boost.{a}", f"boost on {a} visible on {b} (no stale fragment cache)", btxt in message_block(page, mid), r.short())

    # avatar change on one app served fresh by the other
    jz = {a: login(a, L("emails.jz"))[0] for a in rc}
    for a in ("rails", "symfony"):
        b = other(a)
        # fresh_user_avatar_url's v= is updated_at.to_fs(:number) (1 s resolution) and Thruster caches
        # avatars publicly by URL: two uploads in the same second share a URL in Rails itself.
        time.sleep(1.2)
        t = csrf_for(jz[a], "/users/me/profile")
        r = jz[a].post_multipart("/users/me/profile", [("user[name]", "JZ")], [("user[avatar]", f"jz-{a}.png", "image/png", png(128, 128, seed=ord(a[0])))], csrf=t, method_override="patch")
        ua = user_lookup(rc[a], "JZ")
        ub = user_lookup(rc[b], "JZ")
        same = bool(ua and ub and path_of(ua["avatar_url"]) == path_of(ub["avatar_url"]))
        okb, evb = fetch_image(rc[b], ub["avatar_url"]) if ub else (False, "no user")
        if ua and ub:
            ba, bb = rc[a].get(ua["avatar_url"]).body, rc[b].get(ub["avatar_url"]).body
            evb += f" bytes_identical={ba == bb}"
            okb = okb and ba == bb
        check(ph, f"avatar.{a}", f"avatar uploaded on {a}: {b} builds the same fresh URL and serves it", r.status in (302, 303) and same and okb, f"{r.short()} same_url={same} {evb}")

    # concurrent writes
    stats = {"rails": [], "symfony": []}
    before = None

    def writer(app: str, n: int, k: int):
        c = Client(base(app), jar=rc[app].jar.copy())
        for i in range(n):
            t0 = time.time()
            r, mid, _ = post_message(c, ROOM_HQ, f"<div>wordburst{app} thread{k} n{i}</div>", tok[app])
            stats[app].append((r.status, mid, time.time() - t0))

    threads = [threading.Thread(target=writer, args=(a, 15, k)) for a in rc for k in range(6)]
    t0 = time.time()
    [t.start() for t in threads]
    [t.join() for t in threads]
    dur = time.time() - t0
    for a in rc:
        okn = sum(1 for s, m, _ in stats[a] if s == 200 and m)
        worst = max(d for _, _, d in stats[a])
        codes = sorted({s for s, _, _ in stats[a]})
        check(ph, f"burst.{a}", f"{a}: 90 concurrent posts (6 threads) while the other app writes too", okn == 90, f"ok={okn}/90 codes={codes} max={worst:.2f}s total={dur:.1f}s")
    all_ids = [m for a in rc for s, m, _ in stats[a] if m]
    for a in rc:
        r = search(rc[a], "wordburstrails wordburstsymfony".split()[0], tok[a])
        r2 = search(rc[a], "wordburstsymfony", tok[a])
        n = len(set(message_ids(r.text)) | set(message_ids(r2.text)))
        check(ph, f"burst.search.{a}", f"{a} search finds all 180 burst messages", n == 180, f"found={n}")

    # sign-out propagation while both run
    jc, _ = login("rails", L("emails.jason"))
    ok1, _ = signed_in(Client(base("symfony"), jar=jc.jar.copy()))
    snapshot = jc.jar.copy()
    t = csrf_for(Client(base("symfony"), jar=jc.jar.copy()))
    Client(base("symfony"), jar=jc.jar.copy()).post_form("/session", [], csrf=t, method_override="delete")
    r = Client(base("rails"), jar=snapshot).get(f"/rooms/{ROOM_HQ}")
    check(ph, "signout.live", "Rails session works on Symfony, signed out on Symfony, immediately dead on running Rails",
          ok1 and r.status == 302 and "/session/new" in (r.location or ""), r.short())

    session_extras(ph, rc)
    variant_parity(ph, rc, tok)

    # rendering parity: every message written in earlier phases rendered by both apps
    rendering_parity(ph, rc, suites)

    # realtime: subscribe to the HQ message stream on both cables
    realtime(ph, rc, tok)


def crash_phase(ph: str, victim: str, jar: Jar, seed_schema: str) -> None:
    survivor = other(victim)
    start_app(ph, victim)
    c = Client(base(victim), jar=jar.copy())
    t = csrf_for(c)
    stop_flag = threading.Event()
    done = []

    def writer(k: int):
        cc = Client(base(victim), jar=jar.copy())
        i = 0
        while not stop_flag.is_set():
            try:
                r, mid, _ = post_message(cc, ROOM_HQ, f"<div>wordcrash{victim} t{k} n{i}</div>", t)
                if mid:
                    done.append(mid)
            except Exception:
                return
            i += 1

    threads = [threading.Thread(target=writer, args=(k,)) for k in range(4)]
    [th.start() for th in threads]
    time.sleep(2.5)
    D.run(["docker", "kill", "-s", "KILL", D.name(victim)], check=False)
    stop_flag.set()
    [th.join(10) for th in threads]
    app_log_errors(ph, victim)
    D.stop(victim)
    wal = D.shell("ls -la /rails/storage/db | grep production")
    log(f"    killed {victim} after {len(done)} acknowledged posts; files: {' | '.join(wal.splitlines())}")
    start_app(ph, survivor)
    c2 = Client(base(survivor), jar=jar.copy())
    t2 = csrf_for(c2)
    r = search(c2, f"wordcrash{victim}", t2)
    found = set(message_ids(r.text))
    # search shows at most 100 results; check the newest acknowledged ones are all there
    newest = sorted(done)[-50:]
    check(ph, "crash.durable", f"{survivor} sees the acknowledged posts made on {victim} before SIGKILL", bool(done) and set(newest) <= found,
          f"acknowledged={len(done)} newest50_found={len(set(newest) & found)}")
    r, mid, _ = post_message(c2, ROOM_HQ, f"<div>wordaftercrash{survivor}</div>", t2)
    check(ph, "crash.write", f"{survivor} writes after {victim} crashed", bool(mid), r.short())
    stop_app(ph, survivor)
    db_checks(ph, seed_schema, after_crash=True)


def fresh_install_phase(ph: str, first: str, seed_schema: str) -> None:
    """Empty storage: `first` creates the schema and the account (first run); the other runtime then
    boots on it (Rails: db:prepare must see a fully migrated DB) and the data round-trips."""
    second = other(first)
    D.VOLUME = FRESH_VOLUME
    try:
        D.empty_volume()
        start_app(ph, first)
        c = Client(base(first))
        r = c.get("/", follow=True)
        check(ph, "fresh.redirect", f"{first} on empty storage ends up on first run (/ -> /session/new -> /first_run)", r.status == 200 and r.url.endswith("/first_run"), f"{r.status} {r.url}")
        t = c.get("/first_run").csrf()
        email, pw = "admin@fresh.example", "fresh-password-123"
        r = c.post_form("/first_run", [("user[name]", "Fresh Admin"), ("user[email_address]", email), ("user[password]", pw)], csrf=t)
        r2 = c.get("/", follow=True)
        room = int(m.group(1)) if (m := re.search(r"/rooms/(\d+)", r2.url)) else None
        check(ph, "fresh.first_run", f"{first}: first run creates account, admin and a room", r.status == 302 and bool(room), f"{r.short()} room={room}")
        r, mid, _ = post_message(c, room, f"<div>wordfresh{first} hello</div>", r2.csrf())
        check(ph, "fresh.post", f"{first}: post in the first room", bool(mid), r.short())
        jar = c.jar.copy()
        time.sleep(1)
        stop_app(ph, first)
        schema = D.sql("select type, name, tbl_name, sql from sqlite_master where name not like 'sqlite_%' order by name;")
        check(ph, "fresh.schema", f"schema created by {first} identical to the Rails-built seed's sqlite_master", schema == seed_schema,
              "" if schema == seed_schema else diff(seed_schema, schema))
        out = D.sql("select count(*) from schema_migrations; select key || '=' || value from ar_internal_metadata order by key;")
        check(ph, "fresh.meta", f"{first}: schema_migrations count / ar_internal_metadata like the Rails seed",
              out.split()[0] == SEED_META.split()[0] and "environment=production" in out, f"{first}: {out.split()} seed: {SEED_META.split()}")
        start_app(ph, second)
        if second == "rails":
            st = D.exec_("rails", "bin/rails", "db:migrate:status")
            o = st.stdout + st.stderr
            check(ph, "fresh.migrate", f"rails db:migrate:status on a {first}-created DB", st.returncode == 0 and "down" not in o and "NO FILE" not in o,
                  f"rc={st.returncode} ups={o.count(' up ')}")
        ok, ev = signed_in(Client(base(second), jar=jar.copy()), f"/rooms/{room}")
        check(ph, "fresh.session", f"{first}'s first-run session valid on {second}", ok, ev)
        c2, r = login(second, email, pw)
        check(ph, "fresh.login", f"admin created by {first}'s first run logs in on {second}", r.status == 302, r.short())
        r = c2.get(f"/rooms/{room}")
        t = r.csrf()
        check(ph, "fresh.read", f"{second} shows {first}'s first message", mid in message_ids(r.text), r.short())
        r, mid2, _ = post_message(c2, room, f"<div>wordfresh{second} reply</div>", t)
        r3 = search(c2, f"wordfresh{first}", t)
        check(ph, "fresh.write", f"{second} posts and searches on the {first}-created DB", bool(mid2) and mid in message_ids(r3.text), r3.short())
        r = c2.get("/account/edit")
        ok, ev = page_ok(r)
        check(ph, "fresh.account", f"{second}: account settings render (account created by {first})", ok, ev)
        stop_app(ph, second)
        out = D.sql("pragma integrity_check; select (select count(*) from messages) || '=' || (select count(*) from message_search_index);")
        parts = out.split()
        cnt = parts[-1].split("=")
        check(ph, "fresh.db", "integrity ok, FTS rows == messages", parts[0] == "ok" and cnt[0] == cnt[1], " ".join(parts))
    finally:
        for a in D.APPS:
            if D.is_running(a):
                D.stop(a)
        D.cleanup(remove_volume=True)
        D.VOLUME = MAIN_VOLUME


def session_extras(ph: str, rc: dict) -> None:
    """The rest of the cookie contract: return_to_after_authenticating, flash, last_room, transfer links."""
    for a in ("rails", "symfony"):
        b = other(a)
        # return_to stored in _campfire_session by a, consumed by b's sessions#create
        c = Client(base(a))
        r = c.get(f"/rooms/{ROOM_WATERCOOLER}")
        c2 = Client(base(b), jar=c.jar)
        t = c2.get("/session/new").csrf()
        r2 = c2.post_form("/session", {"email_address": L("emails.jason"), "password": PASSWORD}, csrf=t)
        check(ph, f"cookie.return_to.{a}", f"return_to stored by {a} (unauthenticated redirect) honoured by {b}'s sign-in",
              r.status == 302 and r2.status == 302 and (r2.location or "").endswith(f"/rooms/{ROOM_WATERCOOLER}"), f"{a}: {r.short()}; {b}: {r2.short()}")
        # flash written by a, rendered by b
        t = csrf_for(c2, "/users/me/profile")
        ca = Client(base(a), jar=c2.jar)
        r = ca.post_multipart("/users/me/profile", [("user[bio]", f"flash via {a}")], [], csrf=ca.get("/users/me/profile").csrf(), method_override="patch")
        r2 = Client(base(b), jar=c2.jar).get("/users/me/profile")
        has = re.search(r'<div class="flash".*?role="alert"[^>]*>([^<]*)<', r2.text, re.S)
        check(ph, f"cookie.flash.{a}", f"flash set by {a} (profile update) shown by {b} after the redirect",
              r.status == 302 and bool(has) and has.group(1).strip() == "✓", f"{r.short()} flash={has.group(1) if has else None!r}")
        r3 = Client(base(a), jar=c2.jar).get("/users/me/profile")
        check(ph, f"cookie.flash.consumed.{a}", f"flash consumed by {b} is gone on {a}", '<div class="flash"' not in r3.text, "")
        # last_room set by a, used by b's root redirect
        Client(base(a), jar=c2.jar).get(f"/rooms/{ROOM_PETS}")
        r = Client(base(b), jar=c2.jar).get("/")
        check(ph, f"cookie.last_room.{a}", f"last_room cookie set by {a} drives {b}'s root redirect",
              r.status == 302 and (r.location or "").endswith(f"/rooms/{ROOM_PETS}"), f"last_room={c2.jar.cookies.get('last_room')} {r.short()}")
        # per-form CSRF token (Rails 8.2 defaults: per_form_csrf_tokens) rendered by a, submitted to b
        page = Client(base(a), jar=c2.jar).get(f"/rooms/{ROOM_HQ}/involvement").text
        fm = re.search(r'<form[^>]*action="([^"]+)"[^>]*>(.*?)</form>', page, re.S)
        if check(ph, f"csrf.perform.render.{a}", f"{a} renders the involvement button_to form", bool(fm), ""):
            action = unescape(fm.group(1))
            fields = [(unescape(n), unescape(v)) for n, v in re.findall(r'<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"', fm.group(2))]
            fields += [(unescape(n), unescape(v)) for v, n in re.findall(r'<input[^>]*value="([^"]*)"[^>]*name="([^"]+)"', fm.group(2)) if (n, v) not in fields]
            r = Client(base(b), jar=c2.jar).post_form(action, fields)
            check(ph, f"csrf.perform.{a}", f"button_to form with {a}'s per-form authenticity_token accepted by {b}", r.status in (302, 303), f"{action} {r.short()} fields={[f[0] for f in fields]}")
        # transfer link generated by a, redeemed on b
        page = Client(base(a), jar=c2.jar).get("/users/me/profile").text
        m = re.search(r'id="session_transfer_url"', page) and re.search(r'value="([^"]*/session/transfers/[^"]+)"', page)
        if check(ph, f"transfer.link.{a}", f"{a} profile shows a session transfer link", bool(m), ""):
            tid = unescape(m.group(1)).rsplit("/", 1)[1]
            fresh = Client(base(b))
            t = fresh.get(f"/session/transfers/{tid}").csrf()
            r = fresh.post_form(f"/session/transfers/{tid}", [], csrf=t, method_override="put")
            ok, ev = signed_in(fresh)
            check(ph, f"transfer.redeem.{a}", f"transfer link from {a} signs in on {b}", r.status == 302 and ok, f"{r.short()} {ev}")
        # put Jason's HQ involvement back (the per-form check cycled it) so later unread checks see HQ
        jr = Client(base(b), jar=c2.jar)
        jr.post_form(f"/rooms/{ROOM_HQ}/involvement", [("involvement", "mentions")], csrf=csrf_for(jr), method_override="put")


def variant_parity(ph: str, rc: dict, tok: dict) -> None:
    """The same image uploaded through each app: identical blob analysis and byte-identical :thumb variant."""
    img = png(1600, 1000, seed=99)
    ids = {}
    for a in rc:
        r, mid, _ = post_message(rc[a], ROOM_HQ, "", tok[a], attachment=("same.png", "image/png", img))
        ids[a] = mid
    time.sleep(3)
    rows = {}
    for a, mid in ids.items():
        rows[a] = D.sql(f"select b.byte_size, b.checksum, b.metadata, (select vb.checksum || ' ' || vb.byte_size || ' ' || vb.content_type || ' ' || vb.metadata "
                        f"from active_storage_variant_records v join active_storage_attachments va on va.record_type='ActiveStorage::VariantRecord' and va.record_id=v.id "
                        f"join active_storage_blobs vb on vb.id=va.blob_id where v.blob_id=b.id) "
                        f"from active_storage_attachments a join active_storage_blobs b on b.id=a.blob_id where a.record_type='Message' and a.record_id={mid};")
    same = rows["rails"] == rows["symfony"] and rows["rails"] != ""
    check(ph, "variant.parity", "same image via each app: same blob metadata and byte-identical thumb variant", same,
          f"rails: {rows['rails']} || symfony: {rows['symfony']}")


def presence(ph: str, rc: dict, tok: dict, ws: dict) -> None:
    """Presence lives in the shared DB (memberships.connections/connected_at), so it does cross apps:
    Jason present in HQ through one app's cable => a post on the other app must not mark HQ unread for him."""
    for a in ("symfony", "rails"):
        b = other(a)
        jc, _ = login(a, L("emails.jason"))
        cl = CableClient(base(a), jc.jar.header(), origin=base(a))
        cl.recv(5)
        ok = cl.subscribe({"channel": "PresenceChannel", "room_id": ROOM_HQ})
        time.sleep(0.5)
        post_message(rc[b], ROOM_HQ, f"<div>wordpresence{b}</div>", tok[b])
        unread, ev = sidebar_unread(Client(base(b), jar=jc.jar.copy()), ROOM_HQ)
        check(ph, f"presence.{a}", f"Jason present in HQ via {a}'s cable: {b}'s post leaves HQ read", ok and unread is False, f"subscribed={ok} unread={unread}")
        cl.send({"command": "unsubscribe", "identifier": json.dumps({"channel": "PresenceChannel", "room_id": ROOM_HQ})})
        time.sleep(0.8)
        post_message(rc[b], ROOM_HQ, f"<div>wordabsent{b}</div>", tok[b])
        unread, ev = sidebar_unread(Client(base(b), jar=jc.jar.copy()), ROOM_HQ)
        check(ph, f"absence.{a}", f"Jason left HQ on {a}'s cable: {b}'s next post marks HQ unread", unread is True, f"unread={unread}")
        cl.close()


def rendering_parity(ph: str, rc: dict, suites: list[dict]) -> None:
    for A in suites:
        for key in ("mention_msg", "image_msg", "edited_msg", "bot_msg"):
            mid = A.get(key)
            room = ROOM_WATERCOOLER if key == "bot_msg" else ROOM_HQ
            pres = {}
            for a, c in rc.items():
                r = c.get(f"/rooms/{room}/messages/{mid}")
                pres[a] = (r.status, presentation(r.text).replace(D.base_url(a), "BASE"))
            same = pres["rails"] == pres["symfony"] and pres["rails"][0] == 200 and pres["rails"][1]
            check(ph, f"parity.{A['tag']}.{key}", f"message {mid} ({A['tag']} {key}) presentation HTML identical on both apps", bool(same),
                  "" if same else f"rails={pres['rails'][0]} {len(pres['rails'][1])}B symfony={pres['symfony'][0]} {len(pres['symfony'][1])}B :: " + diff(pres['rails'][1], pres['symfony'][1])[:600])


def realtime(ph: str, rc: dict, tok: dict) -> None:
    ws = {}
    for a, c in rc.items():
        page = c.get(f"/rooms/{ROOM_HQ}").text
        m = re.search(r'<turbo-cable-stream-source[^>]*channel="RoomMessagesChannel"[^>]*signed-stream-name="([^"]+)"', page)
        names = re.findall(r'signed-stream-name="([^"]+)"', page)
        try:
            # authenticate each cable with the session the *other* runtime created
            cl = CableClient(base(a), rc[other(a)].jar.header(), origin=base(a))
            welcome = cl.recv(5)
            subs = [cl.subscribe({"channel": "RoomMessagesChannel", "signed_stream_name": unescape(n)}) for n in names]
            ws[a] = cl
            check(ph, f"ws.{a}", f"{a} cable: welcome (cookie from {other(a)}) + subscribe RoomMessagesChannel", bool(welcome and welcome.get("type") == "welcome") and all(subs) and bool(names), f"streams={len(names)} confirmed={sum(subs)}")
        except Exception as e:
            check(ph, f"ws.{a}", f"{a} cable connect", False, repr(e))
    if len(ws) < 2:
        return
    for a in ("rails", "symfony"):
        b = other(a)
        word = f"wordlive{a}"
        r, mid, _ = post_message(rc[a], ROOM_HQ, f"<div>{word}</div>", tok[a])
        got_a = ws[a].wait_for(lambda m: word in json.dumps(m), 5)
        got_b = ws[b].wait_for(lambda m: word in json.dumps(m), 3)
        check(ph, f"live.{a}.self", f"post on {a} delivered live to {a}'s own cable subscriber", bool(got_a), "")
        check(ph, f"live.{a}.cross", f"post on {a} delivered live to {b}'s cable subscriber (NOT expected: separate pub/sub)",
              bool(got_b), "not delivered" if not got_b else "delivered", expected_fail=True)
    presence(ph, rc, tok, ws)
    for cl in ws.values():
        cl.close()


if __name__ == "__main__":
    sys.exit(main())
