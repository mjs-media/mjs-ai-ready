# MJS AI Ready

A WordPress plugin and a report tool for the **AI Ready Upgrade**: a one-off job that makes an existing client's WordPress site easy for search engines and AI search tools (ChatGPT, Claude, Perplexity, Copilot, Google) to reach and describe correctly.

- **The plugin** (`mjs-ai-ready.php`, `inc/`, `assets/`) goes on the client's site. It works on any WordPress site (block themes, Divi, Elementor, Yoast, Rank Math) and doesn't need the MJS Client Kit.
- **The report tool** (`bin/report`, `report/`) runs on Matt's Mac only. It audits a site over SSH and from outside, asks Claude what AI search knows about the business, and writes a branded HTML + PDF report. API keys stay on the Mac.

New builds on the MJS themes already do the basics in the theme (`inc/seo.php`); the MJS Websites theme hands robots.txt, llms.txt and the business schema to this plugin when it's active, so the plugin can go on those sites too.

## Installing on a site

- **Without SSH:** run `bin/build-zip`, then in WP admin go to Plugins → Add New → Upload Plugin and choose `dist/mjs-ai-ready.zip`. Activate, then fill in Settings → AI ready. The same zip is attached to every GitHub release.
- **With SSH:** `./deploy.sh <ssh-host> <wp-path>/wp-content/plugins/mjs-ai-ready`, or copy the zip up and `wp plugin install mjs-ai-ready.zip --activate`.
- **Updates** come from the GitHub release's `mjs-ai-ready.zip`. The repo is public, so sites need no token; they check about every six hours and auto-update. `wp ai-ready status` shows whether updates are working.
- Keep each client's settings in `sites/<domain>.json` so they can be re-applied with `wp ai-ready setup - < sites/<domain>.json`. The folder is git-ignored (the repo is public and settings can include addresses kept off the client's site); it lives in Dropbox only.

## What the plugin does

| | |
| --- | --- |
| **Settings → AI ready** | Business name, schema.org type, plain-English summary, services (one line + page each), address (or town only), areas served, opening hours, contact methods, profile links, FAQs, key pages, Search Console / Bing verification codes. A check at the top shows what's good and what isn't. |
| **robots.txt** | Adds named rules for the 14 search and AI crawlers (same list as the Express Removals theme, `inc/bots.php`). Choice: allow everything (default), or allow search and AI search but block training crawlers (GPTBot, ClaudeBot, Google-Extended, Applebot-Extended), or leave robots.txt alone. Keeps WordPress's and the SEO plugin's own lines. |
| **Physical robots.txt** | If a real `robots.txt` file exists, WordPress's filter never runs. The admin shows a warning, the audit flags it, and `wp ai-ready fix-robots` backs it up and removes it. |
| **/llms.txt** | Built from the settings, then the published pages (minus noindex and the privacy page) and the latest posts. Cached for an hour, rebuilt on save. A real `llms.txt` file (Yoast can write one) wins. |
| **Structured data** | LocalBusiness (or the chosen subtype) with `@id` `/#business` on every page; a Service per service (all on the homepage, each on its own page); FAQPage on pages that show the FAQ block/shortcode. With **Yoast** the nodes go into `wpseo_schema_graph`, with **Rank Math** into `rank_math/json_ld`, so there's one graph and no duplicates. Otherwise its own `<script type="application/ld+json">`. |
| **FAQs on a page** | Block **AI ready FAQs**, or `[ai_ready_faq]` / `[ai_ready_faq ids="1,3" title="Questions"]` (numbers as shown on the settings screen). Works in Divi (Text/Code module) and Elementor (Shortcode widget). |
| **IndexNow** | Key served at `/<key>.txt`. Publishing, updating or unpublishing a page queues a ping to `api.indexnow.org` (sent by WP-Cron 10 s later). Only on live sites: `production` environment and search engines allowed. |
| **Verification** | Prints `google-site-verification` and `msvalidate.01` meta tags on the homepage. Paste the code or the whole tag. |
| **Homepage description** | Optional: uses the summary as the homepage meta description when there's no SEO plugin and the theme doesn't add one. |
| **Updates** | From GitHub releases, like the Client Kit (`inc/updater.php`), using the `mjs-ai-ready.zip` attached to the release. No token needed (public repo). Git checkouts and symlinked copies are never updated. |

## WP-CLI (over SSH)

```
wp ai-ready audit [--format=table|json] [--no-fetch]   # the checks; json is what the report reads
wp ai-ready status                                      # what the plugin is doing here
wp ai-ready setup <file.json|-> [--replace] [--dry-run] # fill in settings from JSON (merges by default)
wp ai-ready export [--paths]                            # settings as JSON
wp ai-ready fix-robots [--yes]                          # back up and remove a physical robots.txt
wp ai-ready llms                                        # print llms.txt
wp ai-ready indexnow [<url>...] [--key-pages]           # ping IndexNow now
```

`setup` accepts the shape in [`examples/ai-ready.example.json`](examples/ai-ready.example.json). Key pages and service URLs can be paths (`/about/`). Feed it from the Mac without copying a file up:

```bash
ssh mjswebsites 'cd ~/public_html && wp ai-ready setup -' < client.json
```

The audit checks: search engines allowed (Settings → Reading), https, physical robots.txt, crawler rules per bot, business details, sitemap (WordPress, Yoast, Rank Math, AIOSEO, SEOPress), llms.txt, homepage structured data by type, Service and FAQ entries, noindex, homepage meta description, H1 headline is text (not an image), verification tags, page descriptions (with Yoast/Rank Math), IndexNow. Check ids are stable (the report keys its copy on them). `inc/bots.php` and `inc/audit.php` don't depend on the rest of the plugin, so the report can run them on sites that don't have it yet.

## Doing an upgrade: the SSH workflow

1. **Before report** (no changes to the site). Plugin not needed yet: the audit code is sent over STDIN to `wp eval-file -` and nothing is written.
   ```bash
   bin/report <ssh-host> <wp-path> https://www.client.co.uk --town="Bexhill-on-Sea" --service="House removals" --service="Storage"
   ```
   Send the client the PDF from `reports/<domain>/`.
2. **Install** the plugin:
   ```bash
   ./deploy.sh <ssh-host> <wp-path>/wp-content/plugins/mjs-ai-ready
   ssh <ssh-host> 'cd <wp-path> && wp plugin activate mjs-ai-ready'
   ```
   Updates need no token (public repo). Never commit secrets or client data to this repo: it's public.
3. **Fill in the details**: copy `examples/ai-ready.example.json`, write the client's facts in plain English (from their site, Google Business Profile and a quick call), then `wp ai-ready setup - < client.json`. Or type them on Settings → AI ready.
4. **Fix what the audit finds**:
   - `wp ai-ready fix-robots` if there's a physical robots.txt (it lists any custom rules that will stop applying; purge LiteSpeed/Cloudflare cache after).
   - Put the **AI ready FAQs** block (or `[ai_ready_faq]`) on the FAQ or contact page.
   - Headline in an image or slider: change it to a real H1 heading in the page builder.
   - Missing descriptions: write them in Yoast/Rank Math, or tick "homepage description".
   - Cloudflare blocking: see below.
5. **Search Console and Bing** (checklist below), then `wp ai-ready indexnow --key-pages`.
6. **After report**: `bin/report … --mode=after` (compares with the latest *before*). Send it.
7. **Re-check in 4–6 weeks**: `bin/report … --mode=recheck`. Put a reminder in the diary when you send the after report.

### Per-site checklist

- [ ] Before report sent
- [ ] Plugin deployed and activated, `wp ai-ready status` clean (updates working)
- [ ] Settings filled in (summary, type, services with pages, town/areas, hours, contacts, profiles, FAQs, key pages)
- [ ] `wp ai-ready audit`: no "need fixing"; physical robots.txt dealt with
- [ ] FAQ block on a page; check it in https://validator.schema.org/ and Google's Rich Results Test
- [ ] Cloudflare (if used): AI crawlers allowed, managed robots.txt off (below)
- [ ] **Google Search Console**:
  - If the client already has a Search Console account, they add Matt's MJS Google account as a user (Settings → Users and permissions → Add user, Full).
  - Otherwise Matt adds the property (URL prefix `https://www.client.co.uk/`), chooses **HTML tag**, pastes the code into Settings → AI ready → Google Search Console, saves, then clicks Verify. A property can have several owners, so this doesn't disturb anyone else's access.
  - Submit the sitemap (`wp ai-ready status` / the audit shows its address: `wp-sitemap.xml`, or `sitemap_index.xml` with Yoast/Rank Math).
- [ ] **Bing Webmaster Tools**: Add site → **Import from Google Search Console** (brings the site and sitemap over, verified). Check the sitemap is listed under Sitemaps; submit it if not. ChatGPT search and Copilot use Bing's index, so this matters.
- [ ] `wp ai-ready indexnow --key-pages`
- [ ] After report sent; re-check booked for 4–6 weeks

### Cloudflare

Cloudflare can block AI crawlers in two places, both in the **Cloudflare dashboard** (not WordPress): **Security → Bots** ("Block AI bots" / AI Crawl Control) and **managed robots.txt**, which adds its own AI rules to robots.txt (the report spots its `BEGIN Cloudflare Managed` block). Turn off what the client doesn't want, or ask whoever manages their Cloudflare account to.

## The report

```bash
bin/report <ssh-host|-> <wp-path|-> <public-url> [options]
```

| Option | |
| --- | --- |
| `--mode=before\|after\|recheck` | Default `before`. *after* and *recheck* compare with the latest `*-before.json` for the site (or `--compare=<file>`). |
| `--business="…"`, `--town="…"`, `--service="…"` (up to two) | For the AI questions. Default: from the plugin settings, then the site's structured data and title. |
| `--no-ai` | Skip the AI questions. |
| `--no-pdf` | HTML and JSON only. |
| `--wp="php ~/wp-cli.phar"` | How to run WP-CLI on that server (default `wp`). |
| `--out=<dir>` | Default `reports/<domain>/` here (git-ignored: client data stays out of the repo). |

Use `- -` instead of host and path for outside checks only (any site, no SSH).

What it does, all read-only:

1. **Server audit** over SSH (`wp ai-ready audit --format=json`, or the audit code over STDIN if the plugin isn't there).
2. **Outside checks**: homepage, robots.txt (per-crawler rules, Cloudflare managed block), llms.txt, sitemap, structured data, description, H1, verification tags. Then the homepage again as each AI crawler (OAI-SearchBot, ChatGPT-User, GPTBot, Claude-SearchBot, Claude-User, ClaudeBot, PerplexityBot, Perplexity-User), compared with a normal browser. 403/429/5xx or a challenge page when the browser got through is reported as **"may be blocking"**: the test borrows the crawlers' names, and real crawlers come from known addresses a firewall may treat differently, so it can't be certain. Googlebot and Bingbot aren't spoofed (firewalls rightly block fake ones).
3. **AI questions**: Claude Opus 5.5 (`claude-opus-5-5`) with web search, answering as an AI search assistant would: *What does [business] in [town] do?* and *Who would you recommend for [service] in [town]?* The report shows each answer (phone numbers removed), whether it mentions the business, and whether it links to the site. Server-side refusal fallbacks are on. Three questions cost a few pence.
4. Writes `YYYY-MM-DD-<mode>.json`, `.html` (fonts embedded, works offline) and `.pdf` (headless Chrome).

**API key.** Never on client servers, never in this repo. Either `ANTHROPIC_API_KEY` / `MJS_ANTHROPIC_API_KEY` in the environment, or (better) the macOS keychain, once:

```bash
security add-generic-password -a mjs -s mjs-ai-ready-anthropic -w
```

(it asks for the key). Without a key the report still runs and leaves out the AI section. The tool always talks to `api.anthropic.com`, ignoring any `ANTHROPIC_BASE_URL` in the shell.

**Copy rules** (in `report/lib/checks.mjs` and `render.mjs`): plain English for small business owners; never promise rankings or that AI tools will recommend the client; llms.txt is a proposed standard; Cloudflare AI-bot blocking is changed in the Cloudflare dashboard; AI answers take weeks to change; no phone numbers anywhere (MJS takes enquiries by the contact form or WhatsApp). Brand: ink `#111`, cyan `#00CFFF`, Anton + Inter (`report/assets/fonts`, from the MJS Websites theme). No price in the report: the upgrade price is set elsewhere.

## Releasing

Like the Client Kit:

1. Bump `Version:` and `MJSAIR_VERSION` in `mjs-ai-ready.php`.
2. Commit, then `git tag vX.Y.Z && git push && git push --tags`.
3. `bin/build-zip`, then `gh release create vX.Y.Z dist/mjs-ai-ready.zip --notes "…"`. The zip must be attached: it's what sites download. Sites pick it up within about six hours and auto-update.

`./deploy.sh <ssh-host> <plugin-path>` copies the working copy straight to a site (lints first; skips the report tool, README and git files).

## Files

| | |
| --- | --- |
| `inc/bots.php` | Crawler list + robots.txt reader (standalone) |
| `inc/audit.php` | The audit (standalone) |
| `inc/options.php` | Settings, defaults, sanitising, business types |
| `inc/robots.php`, `inc/llms.php`, `inc/schema.php`, `inc/faq.php`, `inc/head.php`, `inc/indexnow.php` | The features |
| `inc/admin.php`, `assets/admin.*` | Settings → AI ready |
| `assets/faq-block.js` | FAQ block (no build step) |
| `inc/cli.php` | `wp ai-ready …` |
| `inc/updater.php` | GitHub updates |
| `report/` | The Mac report tool (Node 20+, `@anthropic-ai/sdk`) |
| `bin/build-zip` | Builds `dist/mjs-ai-ready.zip` (plugin files only) |
| `sites/` | Each client's settings JSON (git-ignored, local only) |
