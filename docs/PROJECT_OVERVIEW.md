# EarthHaul Bulk Pages — Project Overview

> Snapshot for resuming this project (or for a new agent / collaborator) without
> needing to read the whole conversation history. Last updated 2026-05-23 after
> consolidating the New Job, Rewrite Page, and Neighborhoods screens into a
> single bulk job flow.

## 1. What this plugin does

A custom WordPress plugin that bulk-creates **location landing pages** (and
eventually **service-location sub-pages**) for **earthhaul.com**, a roll-off
dumpster rental company built on WordPress + Beaver Builder.

Given a single Beaver Builder template page (currently `/locations/orlando-fl/`)
the plugin can:

1. Clone the template into N new draft pages, one per city in a CSV.
2. Replace the **neighborhoods we serve** list on each clone with a
   per-city neighborhood list (CSV-driven, variable count, preserves the
   original `<ul><li><span>` HTML structure).
3. Run AI-driven localization on each clone:
   - **Page text** (hero, FAQ, descriptions, etc.)
   - **Images** (rename + sideload + AI alt text + featured image + row
     backgrounds)
   - **Yoast SEO meta** (title, description, focus keyphrase)
4. Skip everything that should never be touched: Beaver Builder global
   modules, the brand logo, neighborhood lists (those are CSV-managed).

Everything runs from a single **New Job** screen: pick the template,
upload cities CSV (and optionally a neighborhoods CSV), tick which
pipelines to run, click Run. Each city processes through the full
clone -> neighborhoods -> text -> images -> SEO pipeline in a single
AJAX-chunked loop with a live progress bar. Auto-apply (no per-field
review). Writes go to both BB draft and published slots so the live
URL updates immediately, no need to open the BB editor.

## 2. Current state — what works end-to-end

| Capability | Status |
| --- | --- |
| Clone N pages from a Beaver Builder template via cities CSV | ✅ Done |
| CSV-driven neighborhoods list with variable count + class-swap | ✅ Done |
| AI text rewrite across BB layout (skips globals + managed classes) | ✅ Done |
| AI image alt text + filename rewrite + sideload + layout-ref rewrite | ✅ Done |
| Row + column background images included | ✅ Done |
| Featured image (`_thumbnail_id`) included | ✅ Done |
| Brand-asset auto-skip (logo, generic icons) | ✅ Done |
| Yoast SEO title / metadesc / focus keyphrase rewrite | ✅ Done |
| Pipeline toggles (text / images / SEO independently) | ✅ Done |
| Smart skip for already-localized fields (cheap re-runs) | ✅ Done |
| Bulk run across N cities with live progress + cancel | ✅ Done |
| Auto-apply (no diff review) for batch runs | ✅ Done |
| One-click reset (delete all cloned drafts) | ✅ Done |
| **Service sub-pages (cities × services)** | ❌ Not built |
| **Action Scheduler queue + rollback UI** | ❌ Not built |

## 3. Repo layout

```
wordpress-bulk-plugin/
├── earthhaul-bulk-pages/                 # the plugin itself (drop in wp-content/plugins)
│   ├── earthhaul-bulk-pages.php          # plugin header + require_once loader
│   ├── readme.txt
│   └── includes/
│       ├── class-plugin.php              # singleton bootstrap, registers admin hooks
│       ├── admin/
│       │   ├── class-admin-menu.php      # top-level "Bulk Pages" menu + submenus
│       │   ├── class-settings-page.php   # OpenAI key / model / brand voice
│       │   ├── class-new-job-screen.php  # MAIN: form + progress UI + AJAX chunk endpoint
│       │   └── class-inspect-screen.php  # debug: dump walker output for a page
│       └── services/
│           ├── class-openai-client.php   # thin wrapper over Chat Completions
│           ├── class-csv-importer.php    # parses cities CSV (slug, city, state, ...)
│           ├── class-page-cloner.php     # FLBuilderModel::duplicate_post + meta tagging
│           ├── class-layout-walker.php   # schema-driven BB layout inspector
│           ├── class-layout-mutator.php  # set_at_path + dual-slot persist + cache bust
│           ├── class-prompt-builder.php  # text-rewrite system + role-specific prompts
│           ├── class-rewrite-engine.php  # text rewrite orchestration
│           ├── class-image-pipeline.php  # image rename + sideload + AI alt
│           ├── class-yoast-meta-engine.php  # Yoast meta read/rewrite
│           ├── class-neighborhoods-csv-importer.php
│           ├── class-neighborhoods-applier.php  # template-aware list rebuild
│           └── class-bulk-job-runner.php # per-city orchestrator, drives all pipelines
├── docs/
│   ├── PROJECT_OVERVIEW.md                # this file
│   ├── cloning-a-live-wordpress-site-to-localwp.md  # local dev setup notes
│   ├── sample-csv-cities.csv
│   └── sample-csv-neighborhoods.csv
└── scripts/
    ├── dump-bb-layout.php                # MySQL → JSON dump of _fl_builder_data
    ├── analyze-module-fields.php         # which keys hold real content
    ├── analyze-nested-items.php          # repeating-item structure
    ├── test-template-extract.php         # smoke test for neighborhoods rebuilder
    └── test-image-filename.php           # smoke test for filename rewriter
```

## 4. Admin UI map (WP Admin → "Bulk Pages")

- **Dashboard** — status indicators (BB detected? Yoast detected? API key
  configured? cloned drafts count) + a **Reset** button that force-deletes
  every page tagged with `_ehbp_source_post_id` post meta.
- **New Job** — the entire workflow. Pick a BB template (default Orlando),
  set a parent page + title format with `{{city}}/{{state}}` placeholders,
  upload a cities CSV (required), upload a neighborhoods CSV (optional),
  tick which pipelines to run (Text / Images / SEO, all on by default),
  optionally enable dry-run, click Run. The page swaps into a progress
  view with a live log + per-city counters; an AJAX loop processes one
  city per chunk, calling `Bulk_Job_Runner::run_city`. Cancel is honored
  after the in-flight chunk finishes.
- **Inspect Page** — debug screen. Pick a page, see the walker's full
  field inventory (modules, fields, kinds, globals, unknown modules).
  "Download Layout JSON" button dumps raw + walker output for analysis.
- **Settings** — OpenAI API key, model (default `gpt-4o-mini`), brand
  voice prompt, plus a "Test connection" ping button.

## 5. The end-to-end user workflow (from scratch)

1. **One-time on Orlando template:**
   - Open Orlando in BB editor.
   - On the neighborhoods module (HTML / rich-text / list-icon), Advanced →
     Class field → add `ehbp-neighborhoods`.
   - Save.
2. **Prepare CSVs:**
   - `cities.csv` (`slug, city, state`) — one row per city to generate.
   - Optional `neighborhoods.csv` (`city_slug, neighborhood`) — one row
     per neighborhood. The plugin appends state automatically.
3. **WP Admin → Bulk Pages → New Job:**
   - Pick Orlando as the template, set parent + title format.
   - Upload cities CSV (required).
   - Upload neighborhoods CSV (optional).
   - Tick pipelines (default: Text, Images, SEO all on).
   - Leave "Reuse existing cloned drafts" checked unless you want
     duplicates. Skip dry-run unless you just want to validate the CSV.
   - Click Run.
4. **Progress page (auto-rendered after submit):**
   - Each AJAX chunk processes one city's full pipeline:
     clone (or reuse) -> apply neighborhoods -> text rewrite -> image
     rewrite -> Yoast meta -> persist layout to draft + published ->
     bust BB asset cache. ~30–90s per city.
   - Live log shows per-city counters (cloned/reused, neighborhoods
     applied, text applied/skipped/failed, images applied, meta applied).
   - Cancel button sets a flag; the in-flight chunk finishes and the
     loop stops.
5. **After completion:**
   - Final results table lists every city with edit/preview links.
   - Re-running with the same CSV is cheap: smart-skip in every pipeline
     short-circuits already-localized fields without an API call.

## 6. Key design decisions

### 6.1 Schema-driven layout walker (not regex)

`Layout_Walker::MODULE_SCHEMAS` is an explicit per-module-type table:

```
'photo' => [ photo: image_id, photo_src: image_url, data: image_meta, link_url: url ]
'list-icon' => [ items='list_items', item_fields=[title: text] ]
'uabb-faq'  => [ items='faq_items', item_fields=[faq_question: text, faq_answer: html] ]
...
```

We iterated on this twice. First pass was regex heuristics over key names —
too noisy, surfaced lots of styling/config fields. Second pass replaced
that with strict allow-lists. Final pass (current) is schema-per-module
derived by literally dumping a real Orlando BB layout via
`scripts/dump-bb-layout.php` + `analyze-module-fields.php` and
hand-curating the result.

Unknown modules emit a warning instead of guessing.

### 6.2 BB writes go to BOTH slots, always

BB stores the layout twice: `draft` (what the BB editor shows) and
`published` (what the live URL renders). Initial implementation only
wrote to `draft` — caused a real-world bug where applied rewrites only
showed up after manually opening the BB editor and clicking "Publish."

The dual-slot write lives in `Layout_Mutator::persist_layout()` (called
by `Bulk_Job_Runner` after each city's text + image edits) and is also
done internally by `Neighborhoods_Applier::apply()`. Both call
`FLBuilderModel::delete_asset_cache($post_id)` so the live URL
regenerates its CSS/JS on next request. Saves the manual round-trip.

### 6.3 Class-based detection over heuristics

Original neighborhoods detection plan was a scoring heuristic ("look for
list-icon modules near a heading containing 'neighborhood'"). User
correctly pushed back: just put a CSS class on the module and target
that. Way simpler, way more reliable, no false positives, easy to extend.

The class is `ehbp-neighborhoods`. Stored in BB's `settings->class`
(module Advanced tab). Reused as a "skip me" signal in `Rewrite_Engine`
and `Image_Pipeline` so re-running rewrites never clobbers CSV-driven
content.

### 6.4 Template-preserving rebuild for neighborhoods

User showed us their actual HTML structure:

```html
<ul class="location-info orlando-florida">
  <li class="location-info-list-item address">
    <span>Downtown Orlando, FL</span>
  </li>
  …
</ul>
```

Initial rebuilder dumped flat `<div>name, FL</div>` (wrong — broke the
CSS that keys off the wrapper class and item class). Fixed via DOM
parsing of the **source** post's matching module HTML:

- Take the wrapper element (with attrs/classes intact)
- Take the first item element as the per-item template
- Replace the deepest text node with `{{NAME}}` placeholder
- Render N items from CSV, str_replace the placeholder
- Replace the source-city token in the wrapper class with the target
  city's slug (`orlando-florida` → `celebration-florida`)

`scripts/test-template-extract.php` smoke-tests this against the user's
exact source HTML.

### 6.5 Smart skips reduce token cost on re-runs

Re-running a bulk job on cities that have already been processed used
to make the AI re-rewrite already-localized text (which both wastes
tokens and risks small drift). Now every pipeline checks for "already
localized" before calling OpenAI:

- **Text rewrite:** if field contains target city AND not source city → skip
- **Yoast meta:** same rule
- **Image alt text:** if filename didn't change AND alt contains target city → skip
- **Image (whole candidate):** if filename has no source token → skip (brand asset)

Re-runs now cost basically nothing for already-done pages.

### 6.6 Image pipeline scope

`Image_Pipeline::collect_candidates()` reads from three sources:

1. The walker's image fields on non-global, non-managed-class modules
2. The raw BB layout for `row` + `column` `bg_image` settings
   (walker doesn't enumerate these — they're config, not module content)
3. WordPress `_thumbnail_id` post meta (the featured image, used by
   Yoast for og:image and themes for archive thumbnails)

All three are deduped by attachment ID and processed identically:
sideload a renamed copy, save AI alt text on the new attachment, rewrite
the source layout/post-meta to point at the new attachment ID.

The brand-asset filter (filename must contain source city token) keeps
Logo.png and similar shared assets out so the plugin doesn't create
city-specific duplicates of brand imagery.

### 6.7 Prompt engineering — fighting AI tells

System prompt (text rewrites) hard-bans:

- Em-dashes (`—`)
- AI vocabulary: navigate, leverage, unlock, embark, realm, tapestry,
  meticulous, delve, holistic, robust, seamless, vibrant, intricate, etc.
- Hype: amazing, best, top, ultimate
- Synonym substitution (e.g. real-world bug: AI changed "size" →
  "dimensions" on the dumpster sizes section, looked weird)

Temperature lowered from 0.4 → 0.2 to keep output close to original
phrasing.

Per-role prompts (`Prompt_Builder::FIELD_INSTRUCTIONS`) tune behavior
based on field role: `heading`, `body`, `cta_label`, `faq_question`,
`faq_answer`, `meta_description`, etc.

Image alt prompt was tightened in a second pass to ban "ready for X" /
"ideal for Y" speculation — the model was inferring use cases from the
filename rather than describing what's literally in the photo. New
prompt requires the alt to describe only visible content, with optional
trailing `, City, State.`

### 6.8 Apply + reset workflow

`update_post_meta($post_id, '_ehbp_source_post_id', $template_id)` is
the canonical "this page was created by us" tag. Every cloned page
carries it. The Reset button on Dashboard does:

```
SELECT DISTINCT post_id FROM wp_postmeta WHERE meta_key = '_ehbp_source_post_id'
→ wp_delete_post($id, true) for each
```

Never touches the original template page or any non-plugin pages.

## 7. Issues we ran into & how we solved them

| Symptom | Root cause | Fix |
| --- | --- | --- |
| AIWPM upload fails at 300MB / 1GB / on app restart | LocalWP enforces upload limits at PHP, site Nginx, AND router Nginx levels — and regenerates the router config on app restart | Edited PHP `upload_max_filesize` + `post_max_size` + `memory_limit`, site `client_max_body_size`, and runtime router `client_max_body_size`. Documented in `docs/cloning-a-live-wordpress-site-to-localwp.md`. |
| `_fl_builder_data` returned `string` instead of `array` | Double-serialization + multi-byte char corruption when reading via raw mysqli without setting charset | `mysqli->set_charset('utf8mb4')` + `stripslashes` if needed |
| Walker over-reported styling/config fields as content | Regex-on-key-name heuristic was too loose | Replaced with explicit `MODULE_SCHEMAS` per-module-type table |
| Apply only updated BB draft, not live URL | Wrote to `draft` slot only; BB renders from `published` slot | `update_layout_data()` for both `draft` and `published`, then `delete_asset_cache()` (now in `Layout_Mutator::persist_layout()`) |
| AI rewrote "size" → "dimensions" on size labels | Default temperature 0.4 + no synonym-substitution rule | Lowered to 0.2; added explicit "do not swap plain nouns for synonyms" rule with examples |
| Neighborhoods rendered as flat `<div>`s, broke CSS | Initial rebuilder didn't preserve source HTML structure | DOM-parse source module, use wrapper + first `<li>` as templates, str_replace `{{NAME}}` and `{{ITEMS}}` placeholders |
| Hero + featured image missing from image candidates | Image pipeline only walked module fields | Added row/column `bg_image` walker + `_thumbnail_id` reader |
| Logo.png included in image candidates (would create per-city duplicates) | No brand-asset filter | Skip candidates whose filename doesn't contain the source city token |
| Re-running a city showed all rows for re-rewriting | No "already localized" detection | Added smart-skip across text + meta + alt text |
| Three separate admin screens (New Job / Rewrite Page / Neighborhoods) felt fragmented and required clicking each city one-by-one | Initial implementation favored debuggability over UX | Collapsed all three into a single Bulk Job screen with AJAX-chunked progress; auto-apply (no diff review) |
| BB editor freezing in main Chrome profile | Browser extension / cache state | User worked around by using Incognito; not plugin-related |

## 8. What's left, prioritized

### A. Service sub-pages (Phase 1f) — biggest remaining feature

Generate the cities × services matrix originally requested:
`/celebration-fl/concrete-dumpster-rental/`,
`/celebration-fl/residential-dumpster-rentals/`, etc. for every (city,
service) pair.

Concrete tasks:
1. New admin screen "Service Job"
2. Pick a service template (e.g. Orlando's concrete-dumpster-rental page)
3. Pick a cities CSV (reuses existing format)
4. Generate one page per (city, service) combo with predictable URL
   structure
5. Page-type tag (`_ehbp_page_type` post meta = `location` or `service`)
6. Pipeline awareness of page type: service-page alt text and rewrites
   should be allowed to lean into service keywords (the user wanted
   keyword-rich alt for service pages, factual alt for location pages —
   today's prompt is location-only)

### B. Action Scheduler + rollback (Phase 1g)

When jobs span 100+ pages and ~10 minutes of API calls, the AJAX-chunked
flow requires the browser tab to stay open. AS gives:

1. Background job dispatch (no browser tab to keep open)
2. Per-job progress survives tab closures
3. Retry on transient errors
4. Per-job rollback (snapshot `_fl_builder_data` before run, restore
   on undo)

### C. Polish / smaller items

- Page-type-aware prompts (depends on A)
- "Clear OpenAI cache" / re-run single field
- Brand voice tuning UI (today it's a single textarea in Settings)
- Per-city defaults override (e.g. for one city, use a different
  brand voice or skip a specific module)
- Yoast: og:image + twitter card support
- Service sub-page URL hierarchy validation (no orphans)

## 9. How to run locally

Local dev stack: **LocalWP** + the test site under
`~/Local Sites/earthhaul-test/`.

Plugin sits at:
`~/Local Sites/earthhaul-test/app/public/wp-content/plugins/earthhaul-bulk-pages/`

Either symlink the repo or copy the `earthhaul-bulk-pages/` folder into
that path. After `git pull`, the plugin code refreshes immediately —
no rebuild needed.

OpenAI API key is set in WP Admin → Bulk Pages → Settings. Stored in
`wp_options` under `ehbp_api_key`.

## 10. How to extend (cheat sheet)

### Add a new BB module type

1. Open `class-layout-walker.php` → `MODULE_SCHEMAS` constant
2. Add an entry: `'my-module' => [ 'fields' => [ ['key' => 'foo', 'kind' => 'text'] ] ]`
3. If it has repeating items, also set `'items' => 'my_items'` and
   `'item_fields' => [...]`
4. If it switches between icon/photo modes, add
   `'image_type' => ['key' => 'mode', 'photo_value' => 'photo']`
5. Done. `Rewrite_Engine`, `Image_Pipeline`, and the Inspect screen pick
   it up automatically.

### Add a new pipeline (e.g. ACF fields, custom taxonomies)

1. Mirror the `Image_Pipeline` / `Yoast_Meta_Engine` shape: `collect_candidates`,
   `run`, `apply`.
2. Add an entry to the pipelines toggle in `New_Job_Screen::render()` and
   read it into the staged job state in `handle_submit()`.
3. Wire it into `Bulk_Job_Runner::run_city()` next to the existing three
   pipelines, gated on the `pipelines` config flag.
4. Add per-city counter fields (e.g. `'acf' => [...]`) to the result
   shape in `Bulk_Job_Runner` and surface them in `New_Job_Screen::render_log_entry()`
   + `render_results_table()`.

### Add a new managed class (CSV-driven module that AI shouldn't touch)

1. `Rewrite_Engine::MANAGED_CLASSES`: add the class
2. `Image_Pipeline::MANAGED_CLASSES`: add the class
3. Build a screen + CSV importer + applier (mirror the
   `Neighborhoods_*` set)

## 11. Conventions

- **Namespace:** `EarthHaul\BulkPages\Admin\*` and `EarthHaul\BulkPages\Services\*`
- **Class file naming:** `class-foo-bar.php` for `class Foo_Bar`
- **Post meta prefix:** `_ehbp_*`
- **Option prefix:** `ehbp_*`
- **Transient prefix:** `ehbp_*`
- **Admin nonce/action prefix:** `ehbp_*`
- **CSS class prefix (user-facing managed):** `ehbp-*`
- All AI-touched code paths must respect:
  - `is_node_global()` skip
  - `MANAGED_CLASSES` skip
  - "Skip if already localized" smart-skip
  - Globals are NEVER duplicated, mutated, or sideloaded

## 12. Tone-of-voice & content rules (for prompt engineering)

The system prompt enforces these. Don't forget if you regenerate it.

- No em-dashes (`—`). Use commas or periods.
- No AI vocabulary: navigate, leverage, unlock, embark, realm, tapestry,
  meticulous, delve, holistic, robust, seamless, vibrant, intricate.
- No hype: amazing, best, top, ultimate, unparalleled, world-class.
- No synonym substitution (size ↛ dimensions, fast ↛ rapid, big ↛ vast).
- Stay close to the source's phrasing and length.
- City + state localization only — don't invent facts that weren't in
  the original.
- Image alt text: describe what's visible, not what it's "for".

## 13. Next agent / collaborator: read this in this order

1. This file (`docs/PROJECT_OVERVIEW.md`)
2. `earthhaul-bulk-pages/earthhaul-bulk-pages.php` (loader)
3. `earthhaul-bulk-pages/includes/admin/class-new-job-screen.php` (the
   only user-facing workflow — form + progress UI + AJAX endpoint)
4. `earthhaul-bulk-pages/includes/services/class-bulk-job-runner.php`
   (the per-city pipeline orchestrator; one call = one city processed
   end-to-end)
5. `earthhaul-bulk-pages/includes/services/class-layout-walker.php`
   (the source of truth for what content lives where in BB)
6. `earthhaul-bulk-pages/includes/services/class-image-pipeline.php`
   (most complex single service)
7. `earthhaul-bulk-pages/includes/services/class-neighborhoods-applier.php`
   (the template-preserving rebuilder is the gnarliest DOM code in here)

Anything else can be opened on demand.
