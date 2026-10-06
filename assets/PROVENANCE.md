# Frontend assets: provenance

Everything under `assets/` is the reference app's frontend, unchanged, at revision
`254dd1d46f67f2bace6c79d77f0ac026ba4c9226` of basecamp/once-campfire (`reference/`, which pins it):

| Here | From | How |
|---|---|---|
| `images/`, `sounds/`, `stylesheets/` | `reference/app/assets/{images,sounds,stylesheets}` | copied verbatim |
| `javascript/` | `reference/app/javascript` | copied verbatim |
| `vendor/javascript/` | `reference/vendor/javascript` (`@rails--request.js` 0.0.8, `highlight.js/core.js`, `languages/*`) | copied verbatim |
| `gems/<gem>/<path>` | every gem directory on Propshaft's load path in the `campfire-reference:app` image (turbo-rails, stimulus-rails, lexxy, actioncable, activestorage, actiontext, actionview, action_text-trix), plus each gem's license | `assets/script/export_reference.rb`; versions, sources and SHA-256 of every file in `PROVENANCE.gems.md` |

`assets/script/revendor` refreshes all of it (and the fixtures below) from `reference/` and the
reference image. Do not edit these files: the Stimulus controllers and Turbo/Lexxy depend on the
HTML the views render, and the parity tests compare compiled output with the reference app's.

## How they are served

`config/packages/asset_mapper.yaml` lists the directories of Propshaft's load path
(`tests/fixtures/rails/assets/load_path.txt`) in the same order, each at the root namespace, so
AssetMapper's logical paths are Rails' (`application.js`, `controllers/composer_controller.js`,
`turbo.js`, `lexxy.css`, `bell.mp3`, `browsers/chrome.svg`). The set of logical paths equals the
reference app's precompiled manifest (`tests/fixtures/rails/assets/manifest.json`, 314 files).

`importmap.php` is `reference/config/importmap.rb` expanded exactly as importmap-rails expands it
(same keys and order, `pin_all_from` directories included, `index.js` pinned as the directory name),
so `@hotwired/stimulus-loading` finds the controllers under Rails' names. There is no
es-module-shims polyfill (`importmap_polyfill: false`), as in Rails.

Compilation matches Propshaft:

- JavaScript is served byte-for-byte as Rails serves it, except the `//# sourceMappingURL=` of
  `lexxy.js`, `stimulus.min.js` and `turbo.min.js`, which AssetMapper writes relative to the file
  (Propshaft: `/assets/<digested>.map`); both point at the same map.
- CSS `url()` references are resolved against the stylesheet's logical directory and rewritten to
  absolute digested URLs by `App\Asset\PropshaftCssAssetUrlCompiler` (Propshaft's
  `CssAssetUrls`), which replaces AssetMapper's filesystem-relative compiler: Campfire's
  stylesheets write `url(cancel.svg)` for `app/assets/images/cancel.svg`.
- Digests differ (AssetMapper's 7-character digest vs Propshaft's 8 hex characters); file names are
  otherwise the same (`/assets/check-<digest>.svg`).

## Fixtures captured from the reference app

- `tests/fixtures/rails/assets/`: `load_path.txt`, `manifest.json`, `compiled_sha256.json`,
  `compiled/*.css`, `stylesheet_link_tag_all.html`, `javascript_importmap_tags.html`,
  `link_header.txt` — written by `export_reference.rb` (helpers rendered in a real controller).
- `tests/fixtures/rails/layout/session_new.html` and `room_show.html`: `GET /session/new` signed
  out and `GET /rooms/<hq>` signed in as David, from `campfire-reference:app` run with
  `SECRET_KEY_BASE=x DISABLE_SSL=1` on a copy of the parity seed (`var/seed/default`, its files
  under `storage/files`):

  ```sh
  docker run -d --name ref -p 3999:80 -e SECRET_KEY_BASE=x -e DISABLE_SSL=1 -v "$SEEDCOPY:/rails/storage" campfire-reference:app
  curl -c jar -b jar localhost:3999/session/new > session_new.html
  # POST /session with its authenticity_token, david@37signals.com / secret123456, then:
  curl -c jar -b jar localhost:3999/rooms/201306877 > room_show.html
  ```
