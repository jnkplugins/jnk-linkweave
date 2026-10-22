# JnK Linkweave

Internal linking for WordPress that runs on your own server. You get link suggestions in the editor, keyword auto-linking and link reports. It has no credits, and your post content never goes to an outside API.

[Website](https://jnkplugins.com/jnk-linkweave/?utm_source=github) · [Demo video](https://www.youtube.com/watch?v=CuT9KErhgPg) · [Download](https://license.jnkplugins.com/download/jnk-linkweave?src=github)

## What's free

- Ranked internal-link suggestions in the editor sidebar (block editor and Classic)
- TF-IDF + BM25 scoring in plain PHP, so it's unlimited and has no per-suggestion fees
- "Posts that should link here" for inbound links
- Unlimited keyword → URL auto-linking rules, with per-post and per-site caps
- Orphan page report, inbound link counts, daily broken internal-link scan
- Links in / out column on the Posts list
- Background indexing with Action Scheduler
- Rules skip links, code, headings and shortcodes, so your markup stays intact

Pro adds AI re-ranking with your own key, Search Console targeting, focus-keyword boost, bulk URL changes with rollback, broken-link repair, click analytics and more.

## Install

The easy way is the download page:

**[Download JnK Linkweave free](https://license.jnkplugins.com/download/jnk-linkweave?src=github)**

Upload the zip under Plugins > Add New > Upload Plugin and activate it. The zip from that page has a free licence built in, so the plugin updates itself from your WordPress dashboard. You don't type in a key and you don't need an account. Email is optional.

You can also clone this repo into `wp-content/plugins/jnk-linkweave`. That copy works fine, but it **won't update itself**, because the licence comes with the download. Pull new tags yourself, or grab the zip above once and let it handle updates from then on.

Requires WordPress 6.8+ and PHP 8.2+.

## Updates and privacy

The download-page build checks `api.jnkplugins.com` for new versions. That request carries your site address, the free licence key and version numbers. Nothing else leaves your site: link suggestions are scored in PHP on your server, so post content never goes anywhere.

## Pro

Pro is a separate add-on: [JnK Linkweave pricing](https://jnkplugins.com/jnk-linkweave/pricing.php?utm_source=github). It's $29 a year for 3 sites, $49 for 10 or $99 for unlimited, and your renewal price stays what you paid. Lifetime licences start at $89. All three JnK plugins also come as a bundle.

This repo is the free version only. Pro code isn't published here.

## Help

Questions or bugs: open an issue here, or email support@jnkplugins.com.

## License

GPLv2 or later. See `LICENSE.txt`.

The code in this repo is generated from each release, so pull requests can't be merged directly. If you send one anyway, we'll port the change by hand and credit you.
