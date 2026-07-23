=== Coywolf Search ===
Contributors: jonhenshaw
Tags: search, relevance, bm25, full-text search, fuzzy search
Requires at least: 6.3
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Replaces WordPress search with a custom full-text index: BM25 ranking, fuzzy and prefix matching, and per-post-type control.

== Description ==

WordPress searches posts with a `LIKE '%term%'` scan of the posts table. It cannot rank, it cannot tolerate a typo, and it gets slower as a site grows.

Coywolf Search replaces that with a proper search engine, built out of two small database tables of its own:

* **A custom full-text index.** Post content is cleaned — block markup, shortcodes, and HTML stripped — and stored as terms your visitors can actually search. The posts table is never touched.
* **BM25 ranking.** The standard relevance model used by Elasticsearch and Lucene, with `k1` and `b` exposed so you can tune term saturation and how much long posts are penalised.
* **Per-field weights.** A match in the title counts for more than a match halfway down the content. Titles, content, excerpts, and taxonomy terms each get their own multiplier.
* **Fuzzy matching.** "Reciept" still finds "receipt". Off, one character, or automatic — where longer words tolerate two.
* **Prefix matching.** The last word matches as a prefix, so "photog" finds "photography".
* **AND matching with a graceful fallback.** Results must contain every word; if nothing does, you can choose to show posts containing some of them, ranked by how many they cover.
* **Per-post-type and per-taxonomy control.** Choose exactly which post types are searchable and which taxonomies contribute their term names.
* **Optional English stemming and stopwords**, with your own stopword list if the built-in one isn't right for your content.

It replaces search without replacing your theme. `/?s=` and `get_search_form()` keep working, your search template renders the results, and pagination behaves normally — the plugin changes which posts come back, not how they look. If the index is empty, or anything at all goes wrong, WordPress runs its own search instead. Search never breaks the page.

**Privacy and safety**

* Password-protected posts are never indexed. Drafts, private, and scheduled posts enter the index only once they are published, and a post that stops being public is filtered out of results even before the index catches up.
* The plugin makes no outbound network connections of any kind. No telemetry, no phone-home, no remotely-loaded assets.
* Deactivating removes the index and hands search back to WordPress, keeping your settings. Uninstalling removes everything: both tables, every option and transient, and every scheduled event.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/coywolf-search/`, or install it through Plugins → Add New.
2. Activate it.
3. Go to **Settings → Search**, choose what to index, and click **Rebuild index**.

Search starts using the index as soon as the first build finishes. After that, edits are picked up automatically in the background.

== Frequently Asked Questions ==

= Do I have to change my theme? =

No. The plugin answers the query behind `/?s=`, so your theme's existing search form and search results template keep working unchanged.

= What happens when I deactivate it? =

The index tables are dropped and WordPress goes back to its own search immediately. Your settings are kept, so reactivating and rebuilding restores exactly what you had. Nothing you wrote is ever stored only in the index — it is entirely derived from your posts.

= Are drafts or private posts ever exposed? =

No. Only published, non-password-protected posts of the types you selected are indexed, and every search re-checks the post's current status before returning it. A post you unpublish disappears from results straight away, even if the background reindex hasn't run yet.

= Does it call out to any external service? =

No. There are no outbound HTTP requests, no analytics, and no remotely-loaded files. Everything runs on your own server.

= Does it slow down publishing? =

No. Saving a post only flags it; the reindex happens in a background job a minute later, so the editor never waits for it.

= When do I need to rebuild the index? =

Whenever you change something that affects what gets stored — which post types, taxonomies, or fields are indexed, the minimum token length, stopwords, or stemming. The settings screen tells you when a rebuild is due. Ranking settings like weights, `k1`, `b`, fuzzy, and prefix matching apply immediately and never need one.

= Does it support phrase searches in quotes? =

Not in this version. The index stores which terms are in a document, not where they sit, so `"exact phrase"` is treated as the individual words. Quotation marks are ignored rather than silently returning wrong results.

= Does it work with languages that don't use spaces? =

Partly. Words are split on whitespace and punctuation, so languages that don't separate words that way — Chinese, Japanese, Thai — won't tokenize usefully. Stemming is English-only, and can be turned off.

= My host has WP-Cron disabled. Will the index go stale? =

Background reindexing relies on WP-Cron, so with it disabled you will need to rebuild manually from Settings → Search. The settings screen shows how many posts are waiting, so you can tell at a glance.

== Screenshots ==

1. Settings → Search: index status and the batched rebuild.
2. Ranking and matching controls, including BM25 tuning and per-field weights.

== Changelog ==

= 1.0.0 =
* Initial release: custom full-text index, BM25 ranking with per-field weights, fuzzy and prefix matching, per-post-type and per-taxonomy control, optional stemming and stopwords, and a batched index rebuild.
