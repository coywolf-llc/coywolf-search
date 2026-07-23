# WordPress.org Plugin Directory — Full Rulebook

The complete rules the WordPress.org review (automated Plugin Check + heuristic
scanner + human pass) enforces, the exact verification gates, and the specific
mistakes that get plugins rejected. Read this before writing or auditing any
code, markup, or readme for a directory-hosted plugin, and treat the
**Pre-Submission Checklist** (§13) as a hard gate.

Replace `myplugin` / `my-plugin` / `MyPlugin_` with the plugin's real unique 4+
character prefix. `CAPS_PLACEHOLDER` values are yours to fill.

---

## 1. Project setup, headers, and licensing

- **GPL-compatible license.** Ship a `LICENSE` (or `license.txt`) file and a
  `License: GPL-2.0-or-later` (or compatible) header. All bundled code/assets
  must be GPL-compatible.
- **Main file header** must include: `Plugin Name`, `Description`,
  `Version`, `Requires at least`, `Requires PHP`, `Author`, `License`,
  `License URI`, `Text Domain`.
- **Text Domain = plugin slug**, and it must be a **string literal** in every
  `__()`, `_e()`, `esc_html__()`, etc. (a variable text domain is flagged). You
  do **not** need to bundle `.mo` files or call `load_plugin_textdomain()` for a
  directory-hosted plugin — translations come from translate.wordpress.org.
- **`Requires at least` / `Requires PHP` must be honest.** Do not call any API
  newer than your declared minimum. (Example: the template-enhancement output
  buffer in §5 needs WP 6.9+/7.0 — if you use it, your minimum must reflect it.)
- **Slug & name:** descriptive, not generic, and **not** built on a trademark
  you don't own (don't start the slug with someone else's brand, e.g.
  `woocommerce-...`, `google-...`).
- **Version:** increment on every release; keep the main-file `Version` and the
  readme `Stable tag` in sync.

## 2. Security — sanitize input, escape output, validate, authorize

This is the most common rejection bucket. Apply all four, every time.

- **Sanitize every external input** the moment you read it — `$_GET`, `$_POST`,
  `$_REQUEST`, `$_SERVER`, `$_COOKIE`, and anything you later store:
- Plain text → `sanitize_text_field()`
- **URLs / paths → `esc_url_raw()`** — it **preserves percent-encoding**;
    `sanitize_text_field()` **strips `%xx`** and will silently break encoded
    URLs/paths. Use `esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )`.
- A field that holds a **regex pattern** → `sanitize_text_field()` (keeps
    metacharacters; don't use `esc_url_raw` — it would mangle them).
- Email → `sanitize_email()`; integer → `absint()`; array key/slug →
    `sanitize_key()`; rich HTML → `wp_kses_post()` / `wp_kses()` with an allowlist.
- **`json_decode()` is NOT sanitization.** Decode, then sanitize **every
    element** (`absint` / `sanitize_text_field` / `sanitize_key`) before use.
- A save handler that only `trim()`s before storing is **not** sanitized.
- **Escape every output** at the point of output: `esc_html()`, `esc_attr()`,
  `esc_url()`, `esc_textarea()`, `wp_kses_post()`. Use `wp_json_encode()` for JSON.
- **Validate** values against expected sets/ranges (don't just sanitize).
- **Don't create per-input state before you've validated the input is legitimate.**
  A public/anonymous endpoint that writes a **transient, option, row, or file
  keyed by a caller-supplied value** (a UID, slug, post ID, etc.) lets someone
  probe arbitrary values to bloat the options / `postmeta` / custom tables — a
  real review finding, even behind rate limiting (rate limits cap volume, not
  accumulation). Run the existence / ownership / "is this value actually used on
  the site?" check **first**, and write state **only** for values that pass.
  Watch the *ordering*: a per-visitor throttle transient set **before** the
  "is this UID embedded?" gate still bloats — set it **after** the gate. Applies
  to every write, transients included, not just the obvious DB `INSERT`.
- **Authorize + verify intent** on every state change: `current_user_can( … )`
  capability check **and** a nonce (`wp_verify_nonce` / `check_admin_referer` /
  `check_ajax_referer`). Both, not one.
- **Database:** always `$wpdb->prepare()` parameterized queries; never
  interpolate input into SQL. Use the WP options/meta/transient APIs where you can.
  For your own **custom tables**, table/column names can't be bound as normal
  values — use the **`%i` identifier placeholder** (`$wpdb->prepare( 'SELECT … FROM %i WHERE uid = %s', $table, $uid )`),
  which requires **WP 6.2+** (declare it in `Requires at least`), and add a
  justified `// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching`
  (Plugin Check flags direct/uncached queries otherwise).
- **Never use** `eval()`, `create_function()`, dynamic `$$var` callbacks from
  input, `extract()`, or unserialize of untrusted data.

## 3. Prefix everything (avoid collisions)

Every globally-scoped symbol your plugin **defines** must start with a **unique
prefix of 4+ characters** (a distinctive word, not a 2–3 letter abbreviation,
not a common word). Applies to:

- Functions, classes, traits, namespaces, `define()`/constants.
- `update_option()` / `get_option()` / `register_setting()` (group **and**
  option) names; settings page/section slugs.
- `set_transient()` keys, post/term/user meta keys, capabilities, cron hook names.
- Hooks **you** fire (`do_action` / `apply_filters`), `wp_ajax_*` /
  `admin_post_*` actions, nonce action names.
- **Script/style handles** (`wp_register_script/style`) — a vendored library's
  natural handle (e.g. `prism`, `select2`) is a real collision; prefix it.
- `register_post_type` / `register_taxonomy` keys, block names
  (`myplugin/block-name`), REST namespaces (`myplugin/v1`), query vars, JS
  `window.*` globals, and even file-scope/global variables (including in
  `uninstall.php`).

Do **not**:

- Prefix your own symbols with `wp_`, `__`, or a single leading `_` (reserved).
- Wrap your own functions/classes in `function_exists()` / `class_exists()`
  guards (reserved for shared libraries; yours can silently lose to another copy).

Re-applying a **core** hook (e.g. `apply_filters( 'robots_txt', … )`) is fine —
silence the prefix sniff with a `// phpcs:ignore` + justification, don't rename it.

> Scanner note: the automated reviewer can **false-flag** a name whose prefix is
> built from a constant or concatenation it can't resolve (e.g.
> `set_transient( $key )` where `$key = MYPLUGIN_CACHE . '_' . $id`). **A green
> Plugin Check does not guarantee the human reviewer won't re-flag it** — in a
> real review, a const-built option prefix that passed Plugin Check was flagged
> again by the human pass. The reliable fix is to make the literal prefix
> **visible at every call site** — `$key = 'myplugin_' . $bucket . '_' . $id;`
> and store only the *suffix* in the constant (`MYPLUGIN_CACHE = 'bucket'`). The
> runtime keys stay byte-identical, so **no data migration** is needed. Only fall
> back to "point them at the constant" if you genuinely can't restructure the call.

> JS `window.*` globals: Plugin Check does **not** sniff these (it's PHP-focused)
> — only the human/heuristic reviewer does, and it accepts **any casing** as long
> as your vendor prefix is present. `window.myPluginData` (camelCase) and
> `window.MyPluginData` (PascalCase) both pass in live directory plugins; don't
> churn a whole plugin renaming globals to snake_case for casing alone. The one
> historical rejection here was a global with **no resolvable prefix at all**, not
> a cased one — the prefix is what matters, not the case.

## 4. External services & remote calls (Guideline 6)

- **Document every outbound connection** in the readme under a dedicated
  `== External services ==` section. For each service give: **what it is and why
  it's used**, **exactly what data is sent and when**, and **links to its Terms
  of Service and Privacy Policy** (the reviewer will open those links — they must
  resolve). Cover this for every API the plugin calls (AI providers, geocoders,
  font/CDN APIs you actually call, etc.).
- A required external service must provide **functionality of substance**.
- **Do not call files remotely.** Bundle JS/CSS/images/fonts locally; never load
  them from a CDN or another host — **not even as a fallback**. If an asset
  isn't present, omit it; don't fetch it from a remote URL. Use WordPress's
  bundled libraries (jQuery, Requests/`wp_remote_*`, dashicons) instead of your
  own copies.
- All outbound HTTP must go through the WP HTTP API (`wp_remote_*`), with
  timeouts, and only after the user has enabled the feature (no calls on a
  default/idle install).

## 5. Output, buffering, and asset loading

- **No open output buffer.** Don't leave a full-page `ob_start( $callback )`
  open for PHP to flush at shutdown — it can desync other components' buffers.
  If you must transform the whole page, use WordPress 6.9+/7.0's
  **template enhancement output buffer** (`add_filter(
  'wp_template_enhancement_output_buffer', … )`) so core owns the lifecycle; set
  your minimum WP accordingly. A buffer you open **and** `ob_get_clean()` within
  the same function is fine. (Tip: keep the literal `ob_start(` out of comments —
  the reviewer quotes findings by grep-style example.)
- **Never echo raw `<script>` or `<style>` tags** from PHP — a blocker, however
  small. Use `wp_enqueue_script/style` for files and `wp_add_inline_script` /
  `wp_add_inline_style` for inline code (register a src-less handle to attach
  inline CSS). Enqueue on the correct hooks (`wp_enqueue_scripts`,
  `admin_enqueue_scripts`, `enqueue_block_editor_assets`), conditionally.
  - **The one accepted exception is structured data:** a
    `<script type="application/ld+json">…</script>` block for schema (`VideoObject`,
    `Article`, etc.) is **non-executable data**, is how WordPress core and the
    major SEO plugins emit it, and Plugin Check does not flag it. Still build the
    payload with `wp_json_encode()` (add `JSON_HEX_TAG` to neutralize `</script>`).
    Everything **executable** still goes through enqueue.
- Version your enqueued assets so caches bust on update.

## 6. Admin UX — no tracking, no nags, no forced credits

- **No user tracking without explicit opt-in consent** (Guidelines 7 & 9). No
  analytics, telemetry, "phone-home", or beacons by default. Don't send site or
  visitor data anywhere the user didn't enable.
- **Don't hijack the dashboard** (Guideline 11). Admin notices must be **scoped
  to your plugin's own screens** (gate on `get_current_screen()->id`), be
  dismissible or one-time, and on-topic. No ads, upsell banners, "go Pro" nags,
  or review-begging across the admin.
- **Credits / "Powered by" links are opt-in and default-off** (Guideline 10) —
  never inject front-end credit links unless the user turns them on.
- **No trialware / crippled features that require payment to function**
  (Guideline 5) — the directory is for fully-functional GPL software.
- **Hidden but routable admin subpages must keep their parent menu item
  highlighted.** A detail/edit screen reached from a list (e.g. an "Edit item"
  or "Add rule" page) is typically registered under the menu so its URL routes,
  then removed from the menu UI with `remove_submenu_page()` on `admin_head`
  (removing it at *registration* breaks routing and the capability check). With
  no matching menu entry, WordPress highlights nothing and the sidebar looks
  deselected on that screen. Add a `submenu_file` filter that returns the
  visible parent page's slug when `$plugin_page` is one of the hidden children,
  so the parent submenu item stays selected (the top-level menu stays
  highlighted on its own). Verify over real HTTP — read
  `#toplevel_page_<slug> .wp-submenu a.current`.

## 7. Files on disk & permitted file types

- **Locate files with WP functions, not raw paths/`ABSPATH` guesses:**
  `plugin_dir_path()` / `plugin_dir_url()` for plugin files,
  `wp_upload_dir()` for writable files, `get_home_path()` for site-root files
  (load `wp-admin/includes/file.php` first on the front end). Reserve `ABSPATH`
  for the `defined('ABSPATH') || exit;` guard and core requires.
- **Guard direct access** at the top of every PHP file:
  `defined( 'ABSPATH' ) || exit;`.
- **Don't write config or user-code files to disk** (e.g. `.htaccess`,
  user-supplied CSS/JS/PHP) — blockers even in `uploads`. Don't let users save
  **arbitrary code** at all; expose values as form fields and generate the output
  yourself. The prohibition is on **config and executable/user code**, not on
  legitimate **media**: generating a resized image, a `.vtt` caption track, an
  export file, etc. into `wp_upload_dir()` via `WP_Filesystem` or core media
  functions is fine (it's what `uploads` is for). Use `wp_delete_file()` /
  `WP_Filesystem` (never raw `unlink`/`fwrite`/`file_put_contents`), and clean the
  files up in `uninstall.php`.
- **Ship only permitted file types** — extension-less files are rejected. Use
  `.php/.js/.css/.txt/.md/.png/.jpg/.svg/.json/.xml`. Rename a `LICENSE` with no
  extension to `license.txt`. Audit with: `find . -type f ! -name '*.*'`.

## 8. Readme requirements & consistency

- Provide a valid **`readme.txt`** in the WordPress readme format:
- First line `=== Plugin Name ===`, then headers: `Contributors`,
    `Tags` (**≤ 5**, relevant, no keyword/affiliate spam), `Requires at least`,
    `Tested up to`, `Stable tag`, `Requires PHP`, `License`, `License URI`.
- **`Stable tag` must match** the version you're releasing and the main-file
    `Version`.
- A short description (**≤ 150 chars**), then `== Description ==`,
    `== Installation ==`, `== Frequently Asked Questions ==`,
    `== Screenshots ==`, `== Changelog ==`, and `== External services ==` (§4)
    when applicable.
- **Description ↔ FAQ consistency:** the Description must **not contradict** the
  FAQ (the reviewer diffs them), and **every feature mentioned in the FAQ must
  also appear in the Description**. Don't claim "never does X" in one place and
  document an option to do X in another.
- Screenshots referenced in the readme must exist (in the directory's assets);
  don't load them from a remote URL.
- Write for humans; no spammy keyword stuffing or competitor names as tags.

## 9. Block plugins — additional rules

If the plugin's purpose is a block (or it registers blocks):

- **`block.json` is required** per block, with `name` in the form
  `namespace/block-name` (a **unique** namespace — never `core` or `wordpress`),
  `title`, `category`, and at least one of `script`/`editorScript` and one of
  `style`/`editorStyle`. Register with `register_block_type( __DIR__ . '/build' )`.
- **Self-contained:** works on activation with **no external dependencies**
  (no required companion plugin/theme, no signup, no payment, no extra setup).
- **The block is the point:** no admin UX or settings unrelated to the block,
  **minimal server-side PHP** (registration + render), prefer the core REST API
  over custom endpoints, and no ads/nags in the editor.
- Clear, descriptive, non-generic block and plugin titles.

## 10. What must NOT be included (or it gets rejected)

- **No self-updater / update server outside WordPress.org.** A directory-hosted
  plugin updates **only** through .org. Remove any bundled "GitHub updater" /
  custom update-check class, and **don't set an `Update URI` header pointing to a
  non-.org host** (that would block .org updates).
- **No calls to GitHub** (`api.github.com`, `raw.githubusercontent.com`,
  `codeload.github.com`) or any "download/update from GitHub" behavior or copy.
- **No remote-loaded assets** (JS/CSS/images/fonts from a CDN or any host) — see §4.
- **No `.git`, `.github/`, `node_modules/`, tests, build configs, CI files,
  `composer.json`/`composer.lock` dev tooling, `.DS_Store`** in the distributed
  zip. (If you genuinely ship a `/vendor` directory, include its `composer.json`
  too — Plugin Check warns on `vendor` without it. Better: ship runtime deps only.)
- **No obfuscated, packed, or minified-only code** without human-readable source.
- **No tracking, no nags, no trialware, no arbitrary user code, no files written
  to disk** (see §6–§7).
- **No raw `<script>`/`<style>` echoes, no unsanitized input, no unescaped
  output, no un-nonced/un-capability-checked actions** (see §2, §5).
- **No PHP errors, warnings, notices, or deprecations** at runtime (see §12).

## 11. Distribution hygiene

- Keep development scaffolding out of the shipped zip via the points in §10.
- If you build from a repo, produce the distributable as a **clean tree** of
  runtime files only.
- Confirm the zip's folder name = the plugin slug, and the main file's headers
  (Version, Text Domain, Requires) are correct in the **shipped** copy.

## 12. Verification (run these — don't assume)

**A. Plugin Check (the tool the directory runs).** Install the official
[Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin and run it
against the build you will actually submit. Target **0 errors / 0 warnings**.

```bash
# WP-CLI (run against the exact tree/slug you'll submit):
wp plugin check my-plugin-slug

# Or in wp-admin: Tools → Plugin Check → select the plugin → Check it.
```

Investigate every finding. Some warnings are false positives (e.g. a
constant-built prefix, §3) — but the bar is that Plugin Check is **clean or every
remaining item is an explained, annotated false positive**.

**B. WP_DEBUG must be ON and the plugin must be silent.** In `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );    // logs to wp-content/debug.log
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', true );
```

Then exercise **every** path the plugin touches — activation, each admin screen,
each setting save, front-end output, AJAX/REST endpoints, cron jobs,
uninstall — and confirm **zero** entries originating in the plugin's files in
`wp-content/debug.log` (no notices, warnings, or deprecations). Test on the
**oldest PHP and WP you declare support for** and on the current versions.

> Verify over **real HTTP**, not just WP-CLI / `wp eval-file`. The CLI bypasses
> `admin.php` menu routing and the capability re-check for hidden subpages (§6),
> and it pre-loads `wp-admin/includes/*`, so a front-end `get_home_path()` call
> that's missing its `require_once ABSPATH . 'wp-admin/includes/file.php'` guard
> *works* under CLI and only fatals on a real front-end request. Hit the actual
> URLs (logged-in for admin screens) and read the rendered output. Also: when a
> feature's assets are versioned by a **frozen module constant** instead of the
> live plugin version, renaming/altering the JS doesn't bust `?ver=` — a fresh
> test install won't reproduce the resulting stale-asset break; diagnose it by
> grepping the `?ver=` an authenticated page actually enqueues.

**C. Activation/uninstall.** The plugin activates with no errors on a clean
install, and `uninstall.php` (or the uninstall hook) removes its own options,
transients, tables, and cron events without fatals.

***

## 13. Pre-Submission Checklist (hard gate)

Confirm each with evidence before submitting or resubmitting:

- [ ] **Plugin Check = 0 errors / 0 warnings** on the exact submitted build (§12A).
- [ ] **WP_DEBUG on, zero plugin notices/warnings/deprecations** across all paths,
      on min and current PHP/WP (§12B).
- [ ] Every input sanitized with the **right** function (`esc_url_raw` for URLs,
      not `sanitize_text_field`); `json_decode` results sanitized per-element (§2).
- [ ] Every output escaped; all DB access uses `$wpdb->prepare()` (§2).
- [ ] Every state-changing action has a capability check **and** a nonce (§2).
- [ ] **No public/anonymous endpoint writes per-input state** (a transient,
      option, row, or file keyed by a caller-supplied value) **before** validating
      that the value is real / used on the site — gate first, write only on pass,
      and check the *ordering* of every write including transients (§2).
- [ ] Every defined global/option/transient/hook/handle/meta key is **prefixed**
      (4+ chars), including dynamically-built names; the literal prefix is visible
      at the call site (not hidden in a constant the scanner can't resolve) (§3).
- [ ] **`== External services ==`** documents each outbound service: what, when,
      what data, ToS + Privacy links that **resolve** (§4).
- [ ] **No remote file loading** anywhere, not even as a fallback (§4, §10).
- [ ] **No self-updater, no GitHub calls, no `Update URI` to a non-.org host** (§10).
- [ ] **No open `ob_start`**; full-page rewriting uses the WP template-enhancement
      buffer; no raw `<script>`/`<style>` echoes (§5).
- [ ] **No tracking** without opt-in; **no dashboard nags/ads**; admin notices
      scoped to the plugin's screens; credits default-off (§6).
- [ ] **Hidden, routable admin subpages keep their parent menu item highlighted**
      (a `submenu_file` filter points at the visible parent); verified over real
      HTTP, not assumed (§6).
- [ ] Files located via WP functions; **no config/user-code files written to
      disk**; only permitted file types (`find . -type f ! -name '*.*'` is empty) (§7).
- [ ] `readme.txt` valid; **Stable tag matches Version**; ≤ 5 tags; **Description
      and FAQ are consistent** and every FAQ feature appears in the Description (§8).
- [ ] **Plugin URI and Author URI both resolve (no 404) and are different URLs.**
- [ ] Text Domain = slug, used as a string literal everywhere (§1).
- [ ] (Block plugins) valid `block.json`, unique namespace, self-contained,
      minimal PHP, no editor nags (§9).
- [ ] Distributed zip is clean: no `.git`/`.github`/`node_modules`/tests/build
      tooling; folder name = slug (§10–§11).
- [ ] Activates and uninstalls cleanly, removing its own data (§12C).

***

*This prompt distills the WordPress.org
[detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
and
[block-specific guidelines](https://developer.wordpress.org/plugins/wordpress-org/block-specific-plugin-guidelines/),
the checks enforced by [Plugin Check](https://wordpress.org/plugins/plugin-check/),
and hard-won lessons from real directory rejections. Verify rules against the
current guidelines before submitting — the directory's requirements evolve.*