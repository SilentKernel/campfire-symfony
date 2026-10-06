# Parity analysis: /opt/campfire-bench/once-campfire-rust-symfony/parity/out/full1

| seed | cells | pass | flaky | fail | error |
|---|---|---|---|---|---|
| crowd | 25 | 1 | 0 | 24 | 0 |
| custom_styles | 33 | 1 | 0 | 32 | 0 |
| default | 874 | 14 | 0 | 860 | 0 |
| first_run | 16 | 0 | 0 | 16 | 0 |
| restricted | 8 | 0 | 0 | 8 | 0 |
| **all** | 956 | 16 | 0 | 940 | 0 |

| layer | compared | equal | different | notes |
|---|---|---|---|---|
| server | 956 | 458 | 498 | explained only by known bugs: 498 |
| live | 940 | 430 | 510 | explained only by known bugs: 510 |
| aria | 940 | 938 | 2 | explained only by known bugs: 0 |
| pixels | 940 | 938 | 2 |  |
| network | 940 | 0 | 940 | asset-only 0, app responses differ 940, explained by proxy headers + known signatures 864 |
| cable | 940 | 940 | 0 | explained only by known bugs: 0 |

## States with differences (beyond asset-only network)

### account/bots (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### account/bots/edit/with_avatar (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### account/bots/edit/with_webhook (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### account/bots/new (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### account/custom_styles/empty (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### account/custom_styles/filled (seed custom_styles, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### account/edit/admin (seed default, 10 cells, {'fail': 10})
layers: network(app)×10; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### account/edit/crowd (seed crowd, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### account/edit/crowd/next_page (seed crowd, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### account/edit/member (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### account/edit/restricted_room_creation (seed restricted, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### account/edit/saved (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### account/edit/with_logo (seed custom_styles, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### account/edit/with_logo/member (seed custom_styles, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### auth/first_run (seed first_run, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### auth/first_run/completed (seed first_run, 4 cells, {'fail': 4})
layers: live×4, network(app)×4; cells with unexplained text diffs: {'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### auth/first_run/filled (seed first_run, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### auth/first_run/from_sign_in (seed first_run, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### auth/incompatible_browser (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### auth/incompatible_browser/apple_messages (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### auth/join (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### auth/join/completed (seed default, 4 cells, {'fail': 4})
layers: live×4, network(app)×4; cells with unexplained text diffs: {'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### auth/join/existing_email (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### auth/join/invalid_code (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: []; unexplained network entries: ['GET /join/not-a-code (navigation) → 404']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -1,6 +1,5 @@
 GET /join/not-a-code (navigation) → 404
-  headers: cache-control content-encoding content-type referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  headers: cache-control content-type referrer-policy x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: no-cache
-  content-type: text/html
-  vary: accept-encoding
+  content-type: text/html;charset=UTF-8
   body: empty sha256:01ba4719c80b6fe9
```

### auth/sign_in (seed default, 14 cells, {'fail': 14})
layers: network(app)×14; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### auth/sign_in/banned_ip (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: ['POST /session → 429']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -1,3 +1,3 @@
 GET /account/logo?v=20260101160000 → 200
-  headers: cache-control content-disposition content-encoding content-transfer-encoding content-type etag vary x-cache x-rev x-version
+  headers: cache-control content-disposition content-transfer-encoding content-type etag referrer-policy set-cookie x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: max-age=300, public, stale-while-revalidate=604800
@@ -5,6 +5,6 @@
   content-type: image/png
-  vary: accept-encoding
+  set-cookie: _campfire_session; httponly; path; samesite
   body: sha256:d347c43b6c981233
 GET /session/new (navigation) → 200
-  headers: cache-control content-encoding content-type etag link referrer-policy set-cookie vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  headers: cache-control content-encoding content-type etag link referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: max-age=0, must-revalidate, private
@@ -15,6 +15,5 @@
 POST /session → 429
-  headers: cache-control content-encoding content-type referrer-policy set-cookie vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  headers: cache-control content-type referrer-policy set-cookie x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: no-cache
-  content-type: text/html
-  vary: accept-encoding, accept-encoding
+  content-type: text/html;charset=UTF-8
   set-cookie: _campfire_session; httponly; path; samesite
```

### auth/sign_in/custom_styles (seed custom_styles, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### auth/sign_in/error (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### auth/sign_in/rate_limited (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### auth/signed_out_redirect (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### auth/transfer/expired (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: []; unexplained network entries: ['POST /session/transfers/«signed_id:user/transfer:127326141:expires» → 400']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -1,3 +1,3 @@
 GET /session/transfers/«signed_id:user/transfer:127326141:expires» (navigation) → 200
-  headers: cache-control content-encoding content-type etag link referrer-policy set-cookie vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  headers: cache-control content-encoding content-type etag link referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: max-age=0, must-revalidate, private
@@ -8,6 +8,5 @@
 POST /session/transfers/«signed_id:user/transfer:127326141:expires» → 400
-  headers: cache-control content-encoding content-type referrer-policy set-cookie vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  headers: cache-control content-type referrer-policy set-cookie x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: no-cache
-  content-type: text/vnd.turbo-stream.html
-  vary: accept-encoding, accept-encoding
+  content-type: text/vnd.turbo-stream.html;charset=UTF-8
   set-cookie: _campfire_session; httponly; path; samesite
```

### auth/transfer/valid (seed default, 4 cells, {'fail': 4})
layers: live×4, network(app)×4; cells with unexplained text diffs: {'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### errors/404 (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### errors/404/static (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: []; unexplained network entries: ['GET /404.html (navigation) → 200']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -1,5 +1,5 @@
 GET /404.html (navigation) → 200
-  headers: cache-control content-encoding content-type last-modified vary x-cache
+  headers: cache-control content-encoding content-type etag last-modified vary
   cache-control: max-age=2592000, public
-  content-type: text/html
+  content-type: text/html; charset=utf-8
   vary: accept-encoding
```

### errors/422/static (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: []; unexplained network entries: ['GET /422.html (navigation) → 200']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -1,5 +1,5 @@
 GET /422.html (navigation) → 200
-  headers: cache-control content-encoding content-type last-modified vary x-cache
+  headers: cache-control content-encoding content-type etag last-modified vary
   cache-control: max-age=2592000, public
-  content-type: text/html
+  content-type: text/html; charset=utf-8
   vary: accept-encoding
```

### errors/500/static (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: []; unexplained network entries: ['GET /500.html (navigation) → 200']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -1,5 +1,5 @@
 GET /500.html (navigation) → 200
-  headers: cache-control content-encoding content-type last-modified vary x-cache
+  headers: cache-control content-encoding content-type etag last-modified vary
   cache-control: max-age=2592000, public
-  content-type: text/html
+  content-type: text/html; charset=utf-8
   vary: accept-encoding
```

### errors/502/static (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: []; unexplained network entries: ['GET /502.html (navigation) → 200']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -1,5 +1,5 @@
 GET /502.html (navigation) → 200
-  headers: cache-control content-encoding content-type last-modified vary x-cache
+  headers: cache-control content-encoding content-type etag last-modified vary
   cache-control: max-age=2592000, public
-  content-type: text/html
+  content-type: text/html; charset=utf-8
   vary: accept-encoding
```

### interactions/actions_menu (seed default, 10 cells, {'fail': 10})
layers: server×10, live×10, network(app)×10; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/actions_menu/attachment (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/actions_menu/hover_only (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/boost_created (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: ['GET /messages/933434506/boosts → 200', 'GET /messages/933434506/boosts/new → 200']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -1,3 +1,3 @@
 GET /messages/933434506/boosts → 200
-  headers: cache-control content-encoding content-type etag referrer-policy set-cookie vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  headers: cache-control content-encoding content-type etag link referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: max-age=0, must-revalidate, private
@@ -7,5 +7,5 @@
   set-cookie: session_token; httponly; path; samesite
-  body: sha256:fc1190b3b707c7ba
+  body: sha256:be5a1f557ded9da7
 GET /messages/933434506/boosts/new → 200
-  headers: cache-control content-encoding content-type etag referrer-policy set-cookie vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  headers: cache-control content-encoding content-type etag link referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: max-age=0, must-revalidate, private
@@ -15,5 +15,5 @@
   set-cookie: session_token; httponly; path; samesite
-  body: sha256:347dd4e564337857
+  body: sha256:b32c9d53fabf04a5
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"alpha-centuri.webp\"; filename*=UTF-8''alpha-centuri.webp","content_type":"image/webp","service_name":"local"}:expires»/alpha-centuri.webp → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -22,5 +22,6 @@
   vary: accept-encoding
…
```

### interactions/boost_delete_revealed (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/boost_picker (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: ['GET /messages/933434506/boosts/new → 200']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -1,3 +1,3 @@
 GET /messages/933434506/boosts/new → 200
-  headers: cache-control content-encoding content-type etag referrer-policy set-cookie vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  headers: cache-control content-encoding content-type etag link referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: max-age=0, must-revalidate, private
@@ -7,5 +7,5 @@
   set-cookie: session_token; httponly; path; samesite
-  body: sha256:347dd4e564337857
+  body: sha256:b32c9d53fabf04a5
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"alpha-centuri.webp\"; filename*=UTF-8''alpha-centuri.webp","content_type":"image/webp","service_name":"local"}:expires»/alpha-centuri.webp → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -14,5 +14,6 @@
   vary: accept-encoding
+  set-cookie: _campfire_session; httponly; path; samesite
   body: sha256:2b85174a7bc75159
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"black_hole.jpg\"; filename*=UTF-8''black_hole.jpg","content_type":"image/jpeg","service_name":"local"}:expires»/black_hole.jpg → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -21,5 +22,6 @@
   vary: accept-encoding
…
```

### interactions/boost_picker/cancelled (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: ['GET /messages/933434506/boosts → 200', 'GET /messages/933434506/boosts/new → 200']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -1,3 +1,3 @@
 GET /messages/933434506/boosts → 200
-  headers: cache-control content-encoding content-type etag referrer-policy set-cookie vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  headers: cache-control content-encoding content-type etag link referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: max-age=0, must-revalidate, private
@@ -7,5 +7,5 @@
   set-cookie: session_token; httponly; path; samesite
-  body: sha256:626ef2a09f34906a
+  body: sha256:34c0923c23e1f90c
 GET /messages/933434506/boosts/new → 200
-  headers: cache-control content-encoding content-type etag referrer-policy set-cookie vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  headers: cache-control content-encoding content-type etag link referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: max-age=0, must-revalidate, private
@@ -15,5 +15,5 @@
   set-cookie: session_token; httponly; path; samesite
-  body: sha256:347dd4e564337857
+  body: sha256:b32c9d53fabf04a5
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"alpha-centuri.webp\"; filename*=UTF-8''alpha-centuri.webp","content_type":"image/webp","service_name":"local"}:expires»/alpha-centuri.webp → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -22,5 +22,6 @@
   vary: accept-encoding
…
```

### interactions/boost_picker/filled (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: ['GET /messages/933434506/boosts/new → 200']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -1,3 +1,3 @@
 GET /messages/933434506/boosts/new → 200
-  headers: cache-control content-encoding content-type etag referrer-policy set-cookie vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  headers: cache-control content-encoding content-type etag link referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: max-age=0, must-revalidate, private
@@ -7,5 +7,5 @@
   set-cookie: session_token; httponly; path; samesite
-  body: sha256:347dd4e564337857
+  body: sha256:b32c9d53fabf04a5
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"alpha-centuri.webp\"; filename*=UTF-8''alpha-centuri.webp","content_type":"image/webp","service_name":"local"}:expires»/alpha-centuri.webp → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -14,5 +14,6 @@
   vary: accept-encoding
+  set-cookie: _campfire_session; httponly; path; samesite
   body: sha256:2b85174a7bc75159
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"black_hole.jpg\"; filename*=UTF-8''black_hole.jpg","content_type":"image/jpeg","service_name":"local"}:expires»/black_hole.jpg → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -21,5 +22,6 @@
   vary: accept-encoding
…
```

### interactions/composer/attachment_preview (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/composer/attachment_preview_file (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/composer/empty_focused (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/composer/play_sound (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/composer/rich_text_toolbar (seed default, 2 cells, {'fail': 2})
layers: server×2, live×2, network(app)×2; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/composer/sent (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/composer/sent_attachment (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/composer/sent_by_keyboard (seed default, 2 cells, {'fail': 2})
layers: server×2, live×2, network(app)×2; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/composer/upload_in_progress (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/composer/with_text (seed default, 14 cells, {'fail': 14})
layers: server×12, live×12, network(app)×14; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/copy_link (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/edit_form (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/edit_form/attachment (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/edit_form/cancelled (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/edit_form/with_mention (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/edit_saved (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: ['POST /rooms/654632876/messages/933434505 → 302']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -1,3 +1,3 @@
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"alpha-centuri.webp\"; filename*=UTF-8''alpha-centuri.webp","content_type":"image/webp","service_name":"local"}:expires»/alpha-centuri.webp → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -6,5 +6,6 @@
   vary: accept-encoding
+  set-cookie: _campfire_session; httponly; path; samesite
   body: sha256:2b85174a7bc75159
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"black_hole.jpg\"; filename*=UTF-8''black_hole.jpg","content_type":"image/jpeg","service_name":"local"}:expires»/black_hole.jpg → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -13,5 +14,6 @@
   vary: accept-encoding
+  set-cookie: _campfire_session; httponly; path; samesite
   body: sha256:9f987cd3894a484a
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"moon.jpg\"; filename*=UTF-8''moon.jpg","content_type":"image/jpeg","service_name":"local"}:expires»/moon.jpg → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -20,5 +22,6 @@
   vary: accept-encoding
…
```

### interactions/flash_alert (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/flash_notice (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### interactions/lightbox (seed default, 8 cells, {'fail': 8})
layers: server×6, live×6, network(app)×8; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/mention_autocomplete/all (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/mention_autocomplete/empty (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: ['GET /autocompletable/users?room_id=654632876&filter=zzz → 200']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -1,3 +1,3 @@
 GET /autocompletable/users?room_id=654632876&filter= → 200
-  headers: cache-control content-encoding content-type etag referrer-policy set-cookie vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  headers: cache-control content-encoding content-type etag referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: max-age=0, must-revalidate, private
@@ -9,11 +9,11 @@
 GET /autocompletable/users?room_id=654632876&filter=zzz → 200
-  headers: cache-control content-encoding content-type etag referrer-policy set-cookie vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
-  cache-control: max-age=0, must-revalidate, private
+  headers: cache-control content-type referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  cache-control: no-cache
   content-type: text/html; charset=utf-8
-  vary: accept, accept-encoding
+  vary: accept
   set-cookie: _campfire_session; httponly; path; samesite
   set-cookie: session_token; httponly; path; samesite
-  body: sha256:01ba4719c80b6fe9
+  body: empty sha256:01ba4719c80b6fe9
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"alpha-centuri.webp\"; filename*=UTF-8''alpha-centuri.webp","content_type":"image/webp","service_name":"local"}:expires»/alpha-centuri.webp → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -22,5 +22,6 @@
…
```

### interactions/mention_autocomplete/inserted (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/mention_autocomplete/results (seed default, 8 cells, {'fail': 8})
layers: server×6, live×6, network(app)×8; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/message_deleted (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/notifications_help (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, pixels×2, aria×2, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0, 'aria': 2}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

pixels @ chromium-phone-light:
```diff
diffPixels=None size=(1170, 2532)
```

aria @ chromium-phone-light:
```diff
-          - emphasis:
-          - emphasis:
```

### interactions/phone_sidebar (seed default, 2 cells, {'fail': 2})
layers: server×2, live×2, network(app)×2; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/pwa_install/default (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### interactions/quick_boost (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: ['GET /messages/933434506/boosts → 200']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -1,3 +1,3 @@
 GET /messages/933434506/boosts → 200
-  headers: cache-control content-encoding content-type etag referrer-policy set-cookie vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  headers: cache-control content-encoding content-type etag link referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: max-age=0, must-revalidate, private
@@ -7,5 +7,5 @@
   set-cookie: session_token; httponly; path; samesite
-  body: sha256:4222febc89b2c43d
+  body: sha256:7cfa079039a5e676
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"alpha-centuri.webp\"; filename*=UTF-8''alpha-centuri.webp","content_type":"image/webp","service_name":"local"}:expires»/alpha-centuri.webp → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -14,5 +14,6 @@
   vary: accept-encoding
+  set-cookie: _campfire_session; httponly; path; samesite
   body: sha256:2b85174a7bc75159
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"black_hole.jpg\"; filename*=UTF-8''black_hole.jpg","content_type":"image/jpeg","service_name":"local"}:expires»/black_hole.jpg → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -21,5 +22,6 @@
   vary: accept-encoding
…
```

### interactions/reply (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/sidebar/new_ping (seed default, 2 cells, {'fail': 2})
layers: server×2, live×2, network(app)×2; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/sidebar/new_ping/touch (seed default, 2 cells, {'fail': 2})
layers: server×2, live×2, network(app)×2; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/sidebar/read_after_visit (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/sidebar/unread (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### interactions/translation_menu (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### messages/autolinked (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/boosts/many (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/boosts/none (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/boosts/one (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/boosts/text_boost (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/boosts/yours (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/code_with_language (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/code_without_language (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/edited (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/emoji_only (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/file (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/file_unvariable_image (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/formatting (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/from_bot (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/from_deactivated_user (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/image (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/image_downscaled (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/long (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/mention (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/mention/mentioned (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/mention_marshal_era_sgid (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/opengraph_embed (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/opengraph_trix_twitter_avatar (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/plain (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/solo_unfurled_link (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/sound_with_image (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/sound_with_text (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/table (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/threaded (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### messages/video (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### pwa/install/absent_chrome (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### pwa/install/absent_firefox (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### pwa/install/firefox_android (seed default, 2 cells, {'fail': 2})
layers: network(app)×2; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### pwa/install/opera_linux (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### pwa/install/safari_ios (seed default, 2 cells, {'fail': 2})
layers: network(app)×2; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### pwa/install/safari_mac (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### pwa/notifications_help/chrome_android (seed default, 2 cells, {'fail': 2})
layers: server×2, live×2, network(app)×2; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### pwa/notifications_help/chrome_ios (seed default, 2 cells, {'fail': 2})
layers: network(app)×2; cells with unexplained text diffs: {}; known: []
network signatures: ['refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### pwa/notifications_help/chrome_mac (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### pwa/notifications_help/chrome_windows (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### pwa/notifications_help/firefox_android (seed default, 2 cells, {'fail': 2})
layers: network(app)×2; cells with unexplained text diffs: {}; known: []
network signatures: ['refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### pwa/notifications_help/firefox_mac (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### pwa/notifications_help/firefox_windows (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### pwa/notifications_help/opera_linux (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### pwa/notifications_help/safari_ios (seed default, 2 cells, {'fail': 2})
layers: network(app)×2; cells with unexplained text diffs: {}; known: []
network signatures: ['refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### pwa/notifications_help/safari_mac (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### realtime/attachment_sent (seed default, 8 cells, {'fail': 8})
layers: server×6, live×6, network(app)×8; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### realtime/boost_removed (seed default, 8 cells, {'fail': 8})
layers: server×6, live×6, network(app)×8; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### realtime/boosted (seed default, 8 cells, {'fail': 8})
layers: server×6, live×6, network(app)×8; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: ['GET /messages/933434506/boosts → 200']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -3,3 +3,3 @@
 GET /messages/933434506/boosts → 200
-  headers: cache-control content-encoding content-type etag referrer-policy set-cookie vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  headers: cache-control content-encoding content-type etag link referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: max-age=0, must-revalidate, private
@@ -9,5 +9,5 @@
   set-cookie: session_token; httponly; path; samesite
-  body: sha256:054ddc1edf553c59
+  body: sha256:57eb47bd3642c889
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"alpha-centuri.webp\"; filename*=UTF-8''alpha-centuri.webp","content_type":"image/webp","service_name":"local"}:expires»/alpha-centuri.webp → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -16,5 +16,6 @@
   vary: accept-encoding
+  set-cookie: _campfire_session; httponly; path; samesite
   body: sha256:2b85174a7bc75159
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"alpha-centuri.webp\"; filename*=UTF-8''alpha-centuri.webp","content_type":"image/webp","service_name":"local"}:expires»/alpha-centuri.webp → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -23,5 +24,6 @@
   vary: accept-encoding
…
```

### realtime/message_deleted (seed default, 8 cells, {'fail': 8})
layers: server×6, live×6, network(app)×8; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### realtime/message_edited (seed default, 8 cells, {'fail': 8})
layers: server×6, live×6, network(app)×8; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: ['POST /rooms/654632876/messages/933434505 → 302']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -3,3 +3,3 @@
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"alpha-centuri.webp\"; filename*=UTF-8''alpha-centuri.webp","content_type":"image/webp","service_name":"local"}:expires»/alpha-centuri.webp → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -8,5 +8,6 @@
   vary: accept-encoding
+  set-cookie: _campfire_session; httponly; path; samesite
   body: sha256:2b85174a7bc75159
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"alpha-centuri.webp\"; filename*=UTF-8''alpha-centuri.webp","content_type":"image/webp","service_name":"local"}:expires»/alpha-centuri.webp → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -15,5 +16,6 @@
   vary: accept-encoding
+  set-cookie: _campfire_session; httponly; path; samesite
   body: sha256:2b85174a7bc75159
 GET /rails/active_storage/disk/«signed_id:blob_key:{"key":"«blob-key»","disposition":"inline; filename=\"black_hole.jpg\"; filename*=UTF-8''black_hole.jpg","content_type":"image/jpeg","service_name":"local"}:expires»/black_hole.jpg → 200
-  headers: cache-control content-disposition content-encoding content-type last-modified referrer-policy vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
+  headers: accept-ranges cache-control content-disposition content-type etag last-modified referrer-policy set-cookie vary x-content-type-options x-frame-options x-permitted-cross-domain-policies x-xss-protection
   cache-control: max-age=3600, public
@@ -22,5 +24,6 @@
   vary: accept-encoding
…
```

### realtime/message_sent (seed default, 8 cells, {'fail': 8})
layers: server×6, live×6, network(app)×8; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### realtime/new_room_in_sidebar (seed default, 8 cells, {'fail': 8})
layers: server×6, live×6, network(app)×8; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### realtime/presence_read (seed default, 8 cells, {'fail': 8})
layers: server×6, live×6, network(app)×8; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### realtime/removed_from_room (seed default, 8 cells, {'fail': 8})
layers: server×6, live×6, network(app)×8; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### realtime/room_deleted_then_send (seed default, 8 cells, {'fail': 8})
layers: server×6, live×6, network(app)×8; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### realtime/typing/many (seed default, 8 cells, {'fail': 8})
layers: server×6, live×6, network(app)×8; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### realtime/typing/one (seed default, 8 cells, {'fail': 8})
layers: server×6, live×6, network(app)×8; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### realtime/unread_in_sidebar (seed default, 8 cells, {'fail': 8})
layers: server×6, live×6, network(app)×8; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/closeds/edit/admin (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/closeds/edit/from_open (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/closeds/edit/member (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/closeds/edit/member_creator (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/closeds/new (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/closeds/new/crowd (seed crowd, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/directs/edit (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/directs/edit/group (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/directs/new (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### rooms/involvement/cycled (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/involvement/direct_nothing (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/involvement/everything (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/involvement/invisible (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/involvement/mentions (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/involvement/nothing (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/opens/edit/admin (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/opens/edit/crowd (seed crowd, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/opens/edit/filtered (seed crowd, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/opens/edit/from_closed (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/opens/edit/member (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/opens/new (seed default, 10 cells, {'fail': 10})
layers: network(app)×10; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/opens/new/crowd (seed crowd, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/opens/new/member (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### rooms/opens/new/restricted_member (seed restricted, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: []; unexplained network entries: ['GET /rooms/opens/new (navigation) → 403']

network @ chromium-desktop-light:
```diff
--- reference
+++ candidate
@@ -1,6 +1,5 @@
 GET /rooms/opens/new (navigation) → 403
-  headers: cache-control content-encoding content-type referrer-policy set-cookie vary x-cache x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
+  headers: cache-control content-type referrer-policy set-cookie x-content-type-options x-frame-options x-permitted-cross-domain-policies x-rev x-version x-xss-protection
   cache-control: no-cache
-  content-type: text/html
-  vary: accept-encoding
+  content-type: text/html;charset=UTF-8
   set-cookie: _campfire_session; httponly; path; samesite
```

### rooms/show/busy/last_page (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/busy/page_around_middle (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/busy/page_around_newest (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/busy/scrolled_to_next_page (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/busy/scrolled_to_previous_page (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/busy/top_of_pagination (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/closed (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/designers (seed default, 14 cells, {'fail': 14})
layers: server×12, live×12, network(app)×14; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/designers/custom_styles (seed custom_styles, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/designers/custom_styles/member (seed custom_styles, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/designers/member (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/direct (seed default, 10 cells, {'fail': 10})
layers: server×10, live×10, network(app)×10; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/direct/group (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/direct/with_bot (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/empty (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/non_member (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/open (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/original_with_invitation (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/original_with_invitation/member (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### rooms/show/unrenderable_message (seed default, 4 cells, {'fail': 4})
layers: server×4, live×4, network(app)×4; cells with unexplained text diffs: {'server': 0, 'live': 0}; known: ['apple-glyph', 'escaped-switch-img']
network signatures: ['page-body-hash', 'refresh-empty-body', 'set-cookie-on-public-response']; unexplained network entries: []

### search/custom_styles (seed custom_styles, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### search/empty (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### search/no_results (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### search/recent (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### search/results (seed default, 10 cells, {'fail': 10})
layers: network(app)×10; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### search/results/many (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### search/submitted (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### users/profile (seed default, 10 cells, {'fail': 10})
layers: network(app)×10; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### users/profile/avatar_uploaded (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### users/profile/custom_styles (seed custom_styles, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### users/profile/member (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### users/profile/qr_code (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### users/profile/with_avatar (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### users/push_subscriptions (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### users/push_subscriptions/none (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []

### users/show/admin_viewing_member (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### users/show/banned (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### users/show/bot (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### users/show/bot_deactivated (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### users/show/deactivated (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### users/show/member_viewing_admin (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### users/show/self (seed default, 10 cells, {'fail': 10})
layers: network(app)×10; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### users/show/with_avatar (seed default, 4 cells, {'fail': 4})
layers: network(app)×4; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

### welcome/no_rooms (seed default, 10 cells, {'fail': 10})
layers: network(app)×10; cells with unexplained text diffs: {}; known: []
network signatures: ['set-cookie-on-public-response']; unexplained network entries: []

