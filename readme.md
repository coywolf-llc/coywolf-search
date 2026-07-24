# Coywolf Search

**Version:** 1.4.0

Replaces WordPress search with a custom full-text index: BM25 ranking, fuzzy and prefix matching, and per-post-type control.

WordPress searches posts with a `LIKE '%term%'` scan of the posts table. It cannot rank, it cannot tolerate a typo, and it gets slower as a site grows. Coywolf Search replaces that with a proper search engine built out of two small tables of its own.

## Features

- **Custom full-text index.** Block markup, shortcodes, and HTML are stripped into a text projection before indexing. The posts table is never modified.
- **BM25 ranking** with `k1` and `b` exposed for tuning.
- **Per-field weights** for title, content, excerpt, and taxonomy terms.
- **Fuzzy matching** (off / one character / automatic) and **prefix matching** on the last word.
- **AND matching** with an optional coverage-ranked OR fallback when nothing matches everything.
- **Per-post-type and per-taxonomy control** over what is searchable.
- **Optional English stemming** and built-in or custom stopword lists.
- **Instant as-you-type suggestions.** A browser-side title index answers on the keystroke; debounced full-text results merge in behind it. First result auto-highlighted, arrow-key navigation, Escape and a clear button to reset.
- **Self-maintaining index.** Builds on activation, reindexes edits in the background, and rebuilds itself when a setting changes what would be stored.
- **Read-only search API** at `GET /wp-json/coywolf-search/v1/search?q=…`, returning ranked results with highlighted snippets.
- **No outbound network calls.** No telemetry, no remote assets.

Your theme is untouched: `/?s=` and `get_search_form()` keep working and pagination behaves normally. If the index is empty or the engine errors, WordPress runs its own search instead.

## Architecture

| Piece | File |
| --- | --- |
| Table definitions and teardown | `includes/class-coywolf-search-schema.php` |
| Settings model, defaults, sanitisation | `includes/class-coywolf-search-settings.php` |
| Text cleaning and tokenization | `includes/class-coywolf-search-tokenizer.php` |
| Porter stemmer | `includes/class-coywolf-search-stemmer.php` |
| Index writes, dirty queue, statistics | `includes/class-coywolf-search-indexer.php` |
| Batched full rebuild | `includes/class-coywolf-search-rebuilder.php` |
| Vocabulary cache and term expansion | `includes/class-coywolf-search-vocabulary.php` |
| Query pipeline and BM25 scoring | `includes/class-coywolf-search-query-engine.php` |
| `posts_pre_query` integration | `includes/class-coywolf-search-query-integration.php` |
| REST search endpoint | `includes/class-coywolf-search-rest.php` |
| Typeahead payload | `includes/class-coywolf-search-typeahead.php` |
| Conditional asset loading | `includes/class-coywolf-search-assets.php` |
| Typeahead client | `assets/js/typeahead.js` |
| Settings → Search | `includes/class-coywolf-search-admin.php` |

### Schema

```
coywolf_search_terms      term_id, term (unique), doc_freq
coywolf_search_postings   term_id, post_id, field, tf, dl   PK (term_id, post_id, field)
```

`field` is 0=title, 1=content, 2=excerpt, 3=taxonomy. `dl` carries the total token count of that post's field: BM25 needs the document length to normalise term frequency, and the sum of the *matched* terms' `tf` is not the document length. Denormalising it onto every posting row means the search path reads it in the same fetch as the postings — one query per search.

### Filters

| Filter | Purpose |
| --- | --- |
| `coywolf_search_enabled` | Return false to fall back to native WordPress search for a request. |
| `coywolf_search_results` | Filter the ranked post IDs before pagination. |
| `coywolf_search_should_index` | Exclude a post from the index. |
| `coywolf_search_reindex_delay` | Seconds to debounce reindexing after an edit (default 60). |
| `coywolf_search_rate_limit` | Requests allowed per throttle bucket per minute (default 120; 0 disables). |
| `coywolf_search_client_ip` | Override the address the REST throttle buckets on. |
| `coywolf_search_should_enqueue` | Force the typeahead assets on for a theme with an unusual search form. |
| `coywolf_search_typeahead_cap` | How many titles are sent to the browser (default 10,000). |

<!-- wporg-strip:start — describes the GitHub distribution, which the WordPress.org build is not -->
## Development

Work happens on feature branches merged to `main` by PR. Merging triggers `.github/workflows/release.yml`, which bumps the patch version, tags a GitHub release with the plugin zip, and builds the WordPress.org variant as an artifact.

The GitHub build ships a self-updater so installed copies track releases directly. The WordPress.org build must not, so `.github/build-wporg.sh` strips that class, the marked regions in the main file, and the update-source header — then fails the build if any trace survives.

Deploying to the WordPress.org SVN repository is deliberately manual: run the **Deploy to WordPress.org (manual)** workflow when a release should actually ship.
<!-- wporg-strip:end -->

## Changelog

### 1.4.0
- Empty-state row ("No matching results.") in the dropdown, non-selectable and hidden from assistive software since the live region already announces it.
- Fade transition on open/close, gated behind `prefers-reduced-motion`.
- Seven colour settings (list background/title/snippet; highlighted background/left-border/title/snippet) via the bundled jscolorpicker, emitted as CSS custom properties only when set — unset colours keep the theme-following defaults and forced-colours mode still wins.

### 1.3.0
- Two-layer, salted rate limiting on the search endpoint (spoofed forwarded headers no longer bypass or poison it).
- Query cost caps: 16 tokens per search, 6 fuzzy-expanded, and a hard ceiling on postings rows per request.
- Suggestion payload invalidates on incremental index updates, not just full rebuilds.
- Screen-reader support: no-results and count announcements, ARIA progressbar + status on the rebuild, named and grouped settings controls, focus preserved when a rebuild starts.
- Escape closes suggestions first, clears on the second press; underlined match highlights in titles.
- Result ordering no longer hydrates full post rows before pagination.
- sessionStorage + ETag caching for the suggestion data; the boot config is only computed on pages that load the typeahead.

### 1.2.1
- Suggestions keep a readable minimum width on narrow search fields and stay inside the viewport.
- Hid the browser's native `input[type=search]` clear button, which doubled up with the plugin's.
- Arrow keys, Enter, and Escape work whenever the list is open, not only while the field holds focus.
- Enter with no usable suggestion falls through to submitting the form.

### 1.2.0
- Added instant as-you-type suggestions with first-result highlighting, arrow-key navigation, Escape to clear, and a clear button.
- Suggestions attach to any theme's search form without modifying its markup.
- The search library and the title list load only on first interaction with a search box.
- Added a setting for whether to show the content type beside each suggestion (off by default).

### 1.1.0
- The index now builds itself on activation, and rebuilds itself whenever a setting changes what would be stored.
- Added a public read-only search API at `coywolf-search/v1/search`, returning ranked results with highlighted snippets.
- Reindexing delay after a post is edited is now filterable via `coywolf_search_reindex_delay`.

### 1.0.0
- Initial release: custom full-text index, BM25 ranking with per-field weights, fuzzy and prefix matching, per-post-type and per-taxonomy control, optional stemming and stopwords, and a batched index rebuild.
