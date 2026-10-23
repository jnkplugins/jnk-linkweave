=== JnK Linkweave – Internal Links & Automatic Internal Linking ===
Contributors: suresh1986
Tags: internal links, internal linking, broken links, orphan pages, seo
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.13.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Internal linking for WordPress, on your own server: link suggestions, auto-linking rules, and orphan & broken-link reports. No per-request fees.

== Description ==

**Adding internal links by hand is slow — and the tools that automate it charge you by the suggestion.**

https://www.youtube.com/watch?v=CuT9KErhgPg

Most AI internal-linking plugins meter their suggestions with credits and renew every year, so the more you link the more you pay, and your post content is sent off to someone else's server to be scored. For a blog with hundreds of posts, "just add internal links" turns into a recurring bill.

JnK Linkweave does the whole thing on your own server. It reads your existing posts and pages and suggests contextually relevant internal links right inside the editor, ranks them with TF-IDF and BM25 in PHP, and applies keyword auto-linking rules at render time — with **no external service, no per-suggestion fees, and no post content ever leaving your site**. Index as many posts and create as many rules as you like; nothing is metered.

It's built for the people who actually maintain content at scale: bloggers, SEO and content teams, agencies, and membership or course sites that want serious internal linking without a per-credit meter.

= What you get in the free version =

* **Editor sidebar** with ranked internal-link suggestions for the current post (Gutenberg + Classic Editor / TinyMCE).
* **Server-side TF-IDF + BM25 ranking** — all scoring runs in PHP. No external API calls, no per-request fees, no content sent off-site.
* **"Posts that should link here"** — inbound-link opportunities surfaced right in the editor.
* **Unlimited keyword auto-linking rules** — `keyword → URL` rules with per-post and per-site caps, case sensitivity, `target="_blank"`, and `rel="nofollow"`. No cap on how many rules you create or activate.
* **Orphan-page report** — find published posts with zero inbound internal links.
* **Broken internal-link scan** — a daily check across your link graph flags internal links that 404.
* **Inbound link counts** — see which posts hold the most internal link equity.
* **Post-type control** — choose which post types are indexed and eligible as link targets.
* **Background indexing** via Action Scheduler: rebuilds never run inline on `save_post`. The first index starts by itself after activation, and small sites are indexed in seconds.
* **"Links in / out" column** on the Posts list, so you see at a glance which posts get no links.
* **Tag-aware rewriting** — rules skip content inside `<a>`, `<code>`, `<pre>`, headings, and shortcodes, so existing markup is never mangled.

= Get Pro =

Pro is a flat license — **from $29/year** for 3 sites (or a one-time lifetime option), with no per-suggestion metering ever. Cancel any time and the free tier keeps working forever. See [pricing](https://jnkplugins.com/jnk-linkweave/pricing.php?utm_source=wporg&utm_medium=readme&utm_campaign=listing). Pro adds:

* **AI re-ranking (BYOK)** — sharpen suggestion order with your own OpenAI or Anthropic key.
* **Google Search Console targeting** — import your GSC Queries export to steer links toward pages that already earn search clicks.
* **Yoast / Rank Math focus-keyword boost** — rank suggestions using the SEO focus keyphrases you already set.
* **One-click auto-link map** — build an internal-link map from your topical clusters in a single click.
* **Inbound-link bulk-apply** — add contextual links to the current post into several related posts at once.
* **Cross-site / portfolio linking** — suggest links between the sites on your license, on every plan.
* **Bulk review & approve** — insert several editor suggestions at once.
* **One-click broken-link repair** — unlink or repoint dead internal links, one at a time or all at once.
* **Bulk URL changer** with per-batch rollback, and **orphan-report CSV export**, and **internal-link click analytics** (first-party, on your own server).
* **Related posts**, the **anchor text report** and **link preview cards**.
* **Link check before you publish**: how many internal links the post has, with good matches to add in one click.
* **Dead-end report**: posts that link to none of your other posts, with links in and out for every post.
* **Rules 2.0**: other words for the same link, limit a rule to post types or categories, rel="sponsored", a per-post "no auto-links" switch, CSV import and export.
* **AI assistants (read only)**: let AI tools that use the WordPress Abilities API read your Linkweave reports.

Bundle JnK Linkweave with Trinity Forms or EntryVault for ~20% off, or take all three for ~25% off — one checkout, one renewal, a license key per plugin.

= Why JnK Linkweave instead of credit-metered tools like Link Whisper? =

* **No per-suggestion meter.** Tools like Link Whisper meter AI suggestions with credits; when the credits run out you buy more. JnK Linkweave's suggestions are unlimited because they're computed on your own server.
* **Your content stays put.** Credit-metered tools send your post content to their servers for scoring. Linkweave ranks everything locally in PHP — no content ever leaves your site.
* **Flat pricing, with a lifetime option.** Instead of a yearly credit plan, Pro is a flat license from $29/year, and every tier has a one-time lifetime option (about three years of the annual price). The free tier stays free.
* **The free tier is a real product**, not a locked demo — unlimited rules, orphan/inbound/broken reports, and editor suggestions with no submission caps.

= Privacy =

In JnK Linkweave's free build, relevance scoring runs entirely in PHP on your server, so **no post content ever leaves your site**. There is no telemetry. The zip from jnkplugins.com includes a small update client that asks our license server whether a newer version is out (see "External services").

== External services ==

This plugin does not send your content or data to any third-party service.

The zip from jnkplugins.com checks our license server for updates: once or twice a day it sends your site address, its license key and the installed version to our license server on jnkplugins.com so WordPress can offer the update, and the update itself downloads from there. No post content is sent. Terms: https://jnkplugins.com/terms.php · Privacy: https://jnkplugins.com/privacy.php

Apart from that:

The only other outbound HTTP requests it makes are **to your own site**: the daily broken-link scanner issues a lightweight `HEAD` request to each internal link target (a URL on your own domain) to check whether it still resolves. These are self-requests to your site, filtered to your own home URL — no external endpoint is contacted, and nothing is transmitted off your server.

== Installation ==

1. Download the zip from https://jnkplugins.com/jnk-linkweave/ and upload it under Plugins > Add New > Upload Plugin, or upload the folder to `/wp-content/plugins/jnk-linkweave/`.
2. Activate "JnK Linkweave" through the **Plugins** menu.
3. Open **JnK Linkweave → Dashboard**. Linkweave starts reading your posts by itself after activation; if it still says Queued after a few seconds, click **Run now**.
4. Open any post in the editor — the JnK Linkweave sidebar appears with suggestions once indexing finishes.

For the Classic Editor, the JnK Linkweave button appears at the end of the first TinyMCE toolbar row.

== Frequently Asked Questions ==

= Is JnK Linkweave a free alternative to Link Whisper? =

Yes — it's a self-hosted internal-linking plugin whose free tier covers the everyday job (editor suggestions, unlimited auto-linking rules, orphan/inbound/broken-link reports) with no per-suggestion credits and no content sent off-site. Where credit-metered tools like Link Whisper charge for AI suggestions by the credit and renew yearly, Linkweave ranks links on your own server and only asks for a flat Pro license if you want the advanced features (AI re-ranking, GSC targeting, bulk tools).

= Do you have other plugins that work with this? =

Yes — JnK Linkweave is one of three JnK Plugins:

* [Trinity Forms](https://jnkplugins.com/trinity-forms/?utm_source=wporg&utm_medium=readme&utm_campaign=cross-sell) — flat-priced form builder with Stripe.
* [EntryVault](https://jnkplugins.com/entryvault/?utm_source=wporg&utm_medium=readme&utm_campaign=cross-sell) — Kanban CRM for form submissions.
* [JnK Linkweave](https://jnkplugins.com/jnk-linkweave/?utm_source=wporg&utm_medium=readme&utm_campaign=cross-sell) — server-side internal linking suggestions.

Buy any two at ~20% off or all three at ~25% off — one checkout, one renewal, a separate license key per plugin. See [the bundle pricing](https://license.jnkplugins.com/bundles?utm_source=wporg&utm_medium=readme&utm_campaign=cross-sell).

= Does this send my content to an AI service? =

No. JnK Linkweave computes link relevance entirely on your server using BM25 (or TF-IDF cosine, if you pick it in Settings). Nothing is sent to any external service.

= How long does indexing take? =

Roughly a minute and a half for about 1,000 posts on typical shared hosting in our testing. Indexing runs in the background via Action Scheduler, so you can keep using the site while it works.

= Will it slow down my site? =

The auto-linking rule pass adds only a few tens of milliseconds to the `the_content` filter on a typical post in our testing. Suggestions are computed lazily and cached as transients keyed on post ID + modified time (6-hour TTL).

= How many auto-linking rules can I create? =

As many as you like. JnK Linkweave does not cap the number of rules you create or activate.

= What happens to existing manual links? =

JnK Linkweave never touches existing `<a>` tags. Auto-linking rules skip any content already inside an anchor, code block, heading, or shortcode.

= Can I exclude certain post types? =

Yes — under **JnK Linkweave → Settings**, choose which post types are indexed and which are eligible suggestion targets.

= Does it work with page builders? =

It works with the Block Editor (Gutenberg) and Classic Editor out of the box. Elementor and Beaver Builder content is supported because rules apply on the rendered `the_content` output, not the editor source.

= What happens to my data if I uninstall or my Pro license expires? =

Your data is kept by default. Deactivating or deleting the plugin leaves your index, auto-linking rules, and reports in place — nothing is wiped unless you first enable **Delete data on uninstall** under **JnK Linkweave → Settings**, in which case the tables and options are removed when you delete the plugin. A lapsed Pro license simply disables the premium features (bulk URL changer, broken-link repair, CSV export, and AI re-ranking) and the plugin keeps working like the free tier; it never deletes your data. Renewing your license restores access to the premium features.

= How do I extend it? =

Filters include `jnk_linkweave_is_pro`, `jnk_linkweave_max_active_rules`, `jnk_linkweave_rerank`, `jnk_linkweave_suggestion_post_types`, `jnk_linkweave_suggestion_score`, and `jnk_linkweave_allowed_post_types`. Actions include `jnk_linkweave_rule_applied`, `jnk_linkweave_suggestion_inserted`, and `jnk_linkweave_broken_link_found`.

== Screenshots ==

1. Suggested links in the editor. Linkweave lists your other posts on the same topic, shows the words they share, and inserts a link in one click.
2. Inbound links. Every post as a square, grouped by topic and coloured by how many of your posts link to it, so the gaps stand out.
3. Auto-link rules. Pick a phrase and the page it should link to, and Linkweave links it wherever it appears, with limits per post and per site.
4. Posts nothing links to. Published posts no other post links to, each with a button to find the posts that should link there.
5. Broken links. Links in your posts that lead to a missing page, where they are, and a one-click fix.
6. Pro: link clicks. Which of your internal links visitors actually follow, counted on your own server.
7. The overview. How your internal links are doing at a glance, and what to fix next.

== Development ==

JnK Linkweave is fully open source (GPLv2-or-later) and ships entirely uncompiled
alongside its build output, so no external download is needed to read or rebuild it.

* All PHP is human-readable and shipped as-is (there is no PHP build step).
* The block-editor sidebar's React SOURCE is included in this plugin under
  `editor/src/` (`Sidebar.jsx` and friends). The compiled files under `editor/build/`
  (`index.js` and its CSS) are generated directly from that source — nothing under
  `editor/build/` is authored by hand.
* The build uses @wordpress/scripts (https://www.npmjs.com/package/@wordpress/scripts),
  with the source and output directories set explicitly in package.json:

`npm install`
`npm run build`   (runs: wp-scripts build --webpack-src-dir=editor/src --output-path=editor/build)

Running that regenerates the build output from the source. No other build step is required.

== Changelog ==

= 1.13.2 =
* Fixed: "Check again" on Dashboard > Updates now finds a new version straight away. Before, a release could stay hidden for up to 12 hours unless you used "Check for updates now" in the License card.

= 1.13.1 =
* Changed: the readme says plainly what the free zip's update check sends and where, with current prices, and how to install from the jnkplugins.com zip. No code changes.

= 1.13.0 =
* New: bought Pro? Paste your Pro license key in Settings, under License, and click "Install Pro now". Pro is installed over this version for you, the same way WordPress applies an update, and everything you set up stays. Downloading and uploading the Pro zip still works.
* Changed: the upgrade page points to that box first.

= 1.12.0 =
* New: if a database table could not be created, Linkweave says so on its screens, with the database's error, instead of showing an empty index as complete.
* Changed: the License card looks and works the same in every JnK plugin. In the free version it shows your free license, which keeps the plugin updated, and links to your account if you bought Pro.
* Changed: the upgrade page has a "Download the Pro zip" button and the three steps, and says what WordPress shows when you upload Pro over this version.
* Changed: "licence" is now spelled "license" everywhere.
* Pro: license handling fixes, see the Pro changelog.

= 1.11.2 =
* Fixed: on MariaDB 11.7 and newer the plugin could not create its search index table, because those versions reserve the word VECTOR. Link suggestions stayed empty. Updating creates the table and reads your posts again by itself.

= 1.11.1 =
* Pro: fixed an update notice that could show again after updating. No change in the free version.

= 1.11.0 =
* New: the first index starts by itself after you activate Linkweave. Small sites (up to 500 posts) are read straight away when you click Rebuild index.
* New: if WordPress has not started a queued index after 15 seconds, the Overview offers Run now and does it on the spot. It says "Queued, waiting for WordPress to start it" until then, and "Reading posts N of M" while it works.
* Fixed: a rebuild that hit an error, a timeout or an unwritable temp folder left the Overview on "running" for good. It now says the read stopped and offers to run it again; a run with no progress for 5 minutes counts as stopped.
* New: drafts get link suggestions in the sidebar, ranked on their saved text.
* New: "Links in / out" column on the Posts list.
* Fixed: a link to a draft, pending, scheduled, private or trashed post no longer counts as an internal link (visitors get a 404). It shows as broken until that post is published, and counts the moment it is.

= 1.10.1 =
* Fixed: the Upgrade screen labelled cross-site linking as Agency only. It works on every Pro plan.

= 1.10.0 =
* New: Spanish and Korean translations ship with the plugin, so the admin shows in your site's language.

= 1.9.3 =
* Improved: "Both mention" on suggestions no longer lists everyday words like "shows", "needs" or "says".

= 1.9.2 =
* Fixed: the "More tools" menu at the top of every Linkweave screen opened but could not be clicked, so URL changer, Search Console, Anchor text and Link map were only reachable from the WordPress menu. On phones the screen tabs now get their own row.

= 1.9.1 =
* Improved: in the editor sidebar the match label now sits under the post title, so long titles no longer wrap two words to a line.
* Improved: "Both mention" only lists words that explain why two posts match, not everyday words like "two", "quick" or "plan". Matching itself is unchanged.
* Fixed: after an update, suggestions cached by the previous version could show for up to six hours. Updating now clears them.

= 1.9.0 =
* New: a cleaner admin. Every screen has a short explanation of what it shows and what to do next, and the dashboard gives an overview of your internal links.
* Fixed: auto-link rules could add a second link to a page the post already links to. They now skip pages the post already links to.
* Fixed: if the daily scan ever dropped off WordPress's schedule, the broken-link check and the nightly report refresh stopped without a word. It now puts itself back the next time you open the admin.
* Fixed: the editor said suggestions were ranked by AI when no AI step was set up.

= 1.8.0 =
* New: deactivating the plugin asks one optional question about what went wrong. Answering is optional, skipping deactivates straight away, and nothing from your site content is sent.

= 1.7.0 =
* Fixed: the Applied count on the Rules screen was only recalculated when a rule was saved, so publishing or unpublishing a post that mentions a keyword never changed it. It now recounts about a minute after published content changes.
* Improved: the Applied column explains why 0 can be correct (mentions already inside a link, heading or code, or on the target page are skipped).

= 1.6.0 =
* Fixed: the link graph stored "links" that were never in your posts. Content rendered in a background job turned things like a sortable table header into wp-cron.php, admin-ajax.php and wp-admin URLs, and those showed up in the reports and in the broken-link scan count. Only real front-end page links are recorded now, and the old junk rows are removed.
* Fixed: saving a post did not update Orphans or Inbound Counts; only a full rebuild did. A post's links are refreshed in the background right after it is saved, even with Auto-index on save turned off.
* Fixed: inserting a suggestion in the editor counted the link in Orphans and Inbound Counts straight away, before the post was saved, so an undone or never-saved insert (or one in a draft) still showed up. The reports now follow saved, published content only, and the leftover rows are removed on update.
* Fixed: switching Suggestion ranking back could serve a stale cached list, and every list was labelled "tfidf" even on BM25. Changing the setting, rebuilding or saving links now refreshes the lists, and each list says which ranking produced it.
* Fixed: the Classic Editor button said only "No suggestions for this post yet." for a draft. It now gives the real reason (drafts are indexed when published, too short, not indexed yet).
* Fixed: inserting from the Classic Editor glued the full post title onto the sentence with no space. The link goes on a matching phrase already in your text when there is one, otherwise at the cursor with a space, and relevance is shown in words.
* Fixed: the Dashboard's "N posts indexed" drifted between rebuilds. It counts what is in the index, and ticking a post type in Settings indexes it in the background.
* Fixed: "Posts that should link here" could stay empty after publishing until you clicked Refresh, and said "No inbound opportunities" for a post that was simply not indexed yet.
* Improved: Settings, Add rule, Save rule and Delete rule confirm with a notice.
* Improved: when no phrase matches, the inserted link text drops a short label in front of a colon ("Brand: Title" becomes "Title").
* Added: Suggestions per post and Minimum relevance on the Settings screen (both were already used, with no way to change them).
* Changed: the readme now says the Classic Editor button is in the first toolbar row, where it is.

= 1.5.2 =
* Fixed: with another JnK plugin active, the whole site could stop loading ("There has been a critical error on this website"). Several plugins bundle the same licensing client and PHP's code cache could load it twice. They are safe to run together now.
* Fixed: editing a rule quietly turned "Case sensitive" on, so capitalised mentions stopped linking. Rules keep the options you set.
* Fixed: "Max per site" switched a rule off everywhere instead of capping it. A cap of 3 now gives exactly 3 links across the site.
* Fixed: the rules list's "Applied" count included the target post itself and mentions inside headings and code. It now matches the links visitors see.
* Fixed: "Rebuild index" emptied the Broken Links report, which then said everything was fine. Results survive a rebuild, and a new "Scan now" button checks straight away.
* Fixed: the Orphans report silently left out short posts and had no way to open a row. Every published post is listed, short ones are marked, and each row has Edit and View links.
* Fixed: the plugin reported itself as 1.5.0 on a 1.5.1 install, so browsers kept old admin scripts.
* Fixed: a suggestion was pasted at the end of the paragraph as the full post title. It is now placed on a matching phrase already in your text when there is one. The editor sidebar also picks up a newly published post without a manual Refresh.
* Fixed: "Posts that should link here" showed word fragments ("cach, spe") and raw scores; it now shows whole words and plain labels. The Dashboard links to Inbound Counts, unticking a post type removes it from the index, and the progress check during a rebuild no longer trips the rate limit.
* Improved: the Upgrade page lists everything Pro adds.

= 1.5.0 =
* Maintenance release: version alignment with the Pro build; no free-tier behaviour changes.

= 1.4.1 =
* Fixed: inserting a suggested link from the editor sidebar did nothing on WordPress 6.6 and later. Paragraph content became a rich-text object rather than a plain string in 6.6, and the sidebar rejected anything that was not a string — so every paragraph on a current install was refused with "Select a paragraph block first", even with one selected. This is the plugin's main action and it was unavailable to everybody.
* Fixed: inserting a link into a Classic block duplicated the whole post. The insertion ran without an explicit position, and with no cursor in the block the rich-text helper returned the content twice over with the link stranded between the two copies. Links now go in at the cursor, or at the end of the text when there is no cursor, and Classic blocks are handled through their own editor so the rest of the post is never rewritten.
* Fixed: the toolbar button in the Classic editor always answered "Save the post once before requesting suggestions", including on posts that were saved and published. It looked for the post ID somewhere that only exists in the block editor and never fell through to the field sitting beside it, so the Classic route did not work at all.
* Fixed: the internal version constant read 1.3.0 while the plugin was 1.4.0. Styles and scripts were served with a stale version so browsers could keep old copies after an update, and the routine that applies database changes on upgrade compared the wrong number and skipped them.
* Fixed: a warning such as "click into a paragraph first" replaced the entire list of suggestions, and the only Refresh button lived on the empty view — so one mistaken click emptied the panel with no obvious way back. Warnings now sit above the list and leave it alone.
* Improved: an empty suggestions panel gives the real reason. It used to say the index needed building and offer a button to build it whatever the cause, so a post that was merely too short sent people off to rebuild an index that was already complete, and the message was identical when they returned. It now separates an unbuilt index, a post not yet indexed, a post below the minimum length (with its word count), an excluded post type, and a post with no close enough matches.
* Improved: the shared keywords under each suggestion show the words you actually wrote rather than the trimmed forms used for matching, so "dissolv" and "ran" read as "dissolved" and "range".
* Improved: relevance is described in words — Very close match through Loosely related — instead of a bare number with no scale. The exact figure is still available on hover.
* Improved: the Broken Links report tells "no broken links, last checked at…" apart from "no scan has run yet". Both used to read "Nothing to show."
* Improved: the index summary on the dashboard updates when a rebuild finishes, rather than still reporting nothing indexed until the page was reloaded.

= 1.4.0 =
* Improved: the editor sidebar no longer greets a new install with a bare instruction to go and rebuild the index. It explains that suggestions are ranked against an index of your existing posts, and links straight to the screen that builds it — that one step used to be a prerequisite nobody had been told about.
* New: a start-here card on the dashboard while nothing is indexed, naming what stays empty until it is built (the editor sidebar, the orphan report and the inbound counts).
* Improved: an empty orphan report now says which of the two things it means — every post has an inbound link, or the index was never built — instead of "Nothing to show."
* Improved: "No rules yet." explains what a rule actually does, so the empty Rules screen is usable without reading the docs first.
* Improved: the Rules screen carries one plain line describing what Pro adds. No locked controls and nothing disabled.

= 1.3.0 =
* Fixed: the Broken Links report could not find the most common broken internal link of all — one pointing at a post you have since deleted, unpublished, or renamed. Those links were discarded while the link graph was rebuilt, and because the daily scan rebuilds the graph immediately before checking it, the report stayed empty however many dead links a site had. They are now recorded along with the URL they point at, so the scan can flag them and they survive every later rebuild.
* Improved: the Broken Links report now shows the title of the post each dead link was found in — linked straight to its editor — and the title or URL it points at, instead of raw post IDs.
* Changed: the plugin calls itself JnK Linkweave everywhere now, matching the JnK brand used on the rest of the site. Cosmetic only.

= 1.2.7 =
* Changed: the "Upgrade to Pro" screen's pricing link now carries campaign parameters, so we can tell how many people reach the pricing page from inside the plugin. No content or usage data is collected, and the link can still be changed or replaced with the `jnk_linkweave_upgrade_url` filter.

= 1.2.6 =
* Improved: the JnK Linkweave admin menu now sits with your other content tools, just below Comments, instead of at the very bottom of the menu below Settings.
* Fixed: a keyword rule whose target was the post being viewed would link that post to itself. Rules now skip the page they point at.
* Fixed: link suggestions in the editor sidebar ended with a stray HTML entity instead of an ellipsis.

= 1.2.5 =
* Renamed the plugin to JnK Linkweave (formerly "Automatic Linker") for a more distinctive name.
* Security: reading auto-link rules, index status, and the site-wide analysis reports (orphan pages, inbound counts, broken links) now requires the `manage_options` capability, to match the write side — this data is site-wide and admin-level. The admin menu items now match that capability, so no screen loads with content it cannot fetch.
* Added: an **Inbound Counts** screen (JnK Linkweave → Inbound Counts) listing how many internal links point at each published post.
* Added: a **Post types** setting to choose which post types are indexed and eligible as link targets (previously fixed to Posts and Pages).
* Improved: "Rebuild index" now also refreshes the internal-link graph, so the Orphans and Inbound Counts reports are accurate immediately instead of after the next daily scan.
* Improved: the suggestions-per-post and minimum-relevance settings are now honored as defaults for the editor suggestions.
* Improved: the tokenizer collapses more inflections to a common root (e.g. "running" and "runs") and treats "will" as a stopword, for slightly better link-suggestion matching.
* Updated the bundled Action Scheduler library to 4.0.0. Minimum WordPress is now 6.8.

= 1.2.4 =
* Added: quick links on the Plugins screen — a Settings action link, and Docs / Support links in the plugin row.
* Housekeeping: author/branding updated to JnK Plugins. No change to features.

= 1.2.3 =
* Security: creating or editing auto-linking rules now requires the `manage_options` capability (administrators). Because rules apply site-wide at render time, they should not be editable by lower roles.
* Added: an "External services" section to this readme documenting that the only outbound requests are self-checks to your own site.
* Fixed: a database query in the BM25 ranker now uses a prepared identifier placeholder.
* Housekeeping: internal build tidy-up; no change to free-tier features.

= 1.2.2 =
* Improved: "Posts that should link here" (inbound-link opportunities) now uses the same ranking as the editor suggestions, so it stays consistent instead of sometimes appearing empty.
* Changed: the "Min post length" setting is now "Min indexed words" with a clearer description, and its default is lowered to 40 so typical posts index without adjusting settings.

= 1.2.1 =
* Maintenance and compatibility. The free tier is unchanged.

= 1.2.0 =
* Added: **BM25 ranking** — link suggestions now rank with BM25 (saturates repeated terms and normalizes for post length) for sharper results. Switch back to classic TF-IDF cosine any time under Settings → Suggestion ranking. No reindex needed.
* Added: **Inbound-link opportunities** — the editor sidebar now shows "Posts that should link here": related posts that don't yet link to the one you're editing.

= 1.0.7 =
* Fixed: the "Upgrade to Pro" and "Feedback/Docs" admin screens linked to a retired domain and would 404. Both now point at the current site.
* Changed: leaner free package — it now contains only free-tier code.
* Changed: finished renaming internal identifiers from the plugin's earlier name.

= 1.0.5 =
* Maintenance: internal naming and metadata consistency; no functional changes.

= 1.0.4 =
* Changed: minimum supported PHP is now 8.2 (8.1 reached end-of-life).
* Added: edit existing auto-link rules in place (previously you could only add or delete them).

= 1.0.0 =
* Initial release.
* TF-IDF suggestion engine with cosine similarity and orphan-boost / already-linked penalty.
* Unlimited auto-linking rules with per-post / per-site caps and tag/shortcode protection.
* Orphan-page report, inbound-link counts, and a daily broken-link scanner.
* Gutenberg sidebar and Classic Editor (TinyMCE) integration.
* REST API namespace `jnk-linkweave/v1` with nonce + capability + rate-limit checks.

== Upgrade Notice ==

= 1.0.0 =
First public release.
