# Cycling news sources

Every feed here was fetched and parsed on **14 August 2026** before being written into the plugin. Item counts are from that fetch. Nothing in this file is from memory or from a search result — a feed that used to exist and a feed that returns items are different things, and the difference does not show up until a demo.

## Shipped

| Publisher | Feed | Items | Auth | What it gives you |
| --- | --- | --- | --- | --- |
| **Cyclingnews** | `https://www.cyclingnews.com/rss/` | 50 | none | The richest of the three by a distance. Full article body in `<content:encoded>`, `<category>` tags, byline in both `<author>` and `<dc:creator>`, `<media:thumbnail>`. Uses the `Race Name: what happened` headline convention consistently. |
| **Velo** (Outside) | `https://velo.outsideonline.com/feed/` | 25 | none | Tags items with actual race and rider names as categories, which finds races that a rider-led headline hides. Tags *related* races too — see the gotcha below. |
| **Cycling Weekly** | `https://www.cyclingweekly.com/rss` | 50 | none | Broad UK-centric coverage. Thin structured data: categories are section labels (`Racing`, `News`, `Products`) and it does not use the colon-prefix convention, so almost nothing is derivable from it beyond tech. |

All three are plain RSS 2.0 over HTTPS, no key, no rate limit encountered. They are fetched through `fetch_feed()`, so WordPress's own SimplePie caching and timeouts apply on top of the plugin's 15-minute transient.

### Gotchas found in the real data

**Cyclingnews files most of its output under `Racing`.** 15 of 20 items, including transfer news and quote-led rider pieces. Treating that category as "race report" was wrong about as often as it was right, so the mapper only reads it that way when the headline also names a race.

**Velo tags races the story is *related to*, not the race it is about.** A Pogačar contract story carries `Tour de France` and `Vuelta a España`; a Vuelta preview carries three Grand Tours. Taking the first race-shaped category put the wrong race on four items out of one day. The mapper now requires the category name to appear in the headline too.

**Velo's category casing is inconsistent** — `life time grand prix` sits next to `Tour de France`. The race field is read by people, so the mapper takes the headline's capitalisation of the name rather than the category's.

**Cyclingnews puts an email address in `<author>`** (`laura@cyclingnews.com (Laura Weislo)`) and the bare name in `<dc:creator>`. SimplePie surfaces whichever it found first, so the reader strips the address.

**A product review reads exactly like a race report.** `Fara Gr4 review: Norwegian sensibilities…` and `Insta360 X6 Review: Why the Best…` both open with something that looks like a proper noun followed by a colon. So do feature headlines: `From Moneyball to Sweat Science: How Allen Lim…`. Both classes are excluded by rule, and both are in the test fixture.

## Verified working, not shipped

Free, no auth, returning items on 14 August 2026. Add any of them via the `workflow_discovery_cycling_feeds` filter.

| Publisher | Feed | Items | Note |
| --- | --- | --- | --- |
| road.cc | `https://road.cc/rss` | 25 | UK, strong on advocacy and infrastructure as well as racing. |
| Escape Collective | `https://escapecollective.com/feed/` | 15 | Member-funded, analysis-heavy. Small volume, high quality. |
| INRNG | `https://inrng.com/feed/` | 10 | Long-form analysis, one or two posts a day. Full text in the feed. |
| PezCycling News | `https://pezcyclingnews.com/feed/` | 12 | |
| Cyclist | `https://www.cyclist.co.uk/feed` | 10 | |
| Cycling Magazine (Canada) | `https://cyclingmagazine.ca/feed/` | 10 | |
| Bicycling | `https://www.bicycling.com/rss/all.xml` | 50 | US, more fitness and gear than racing. |
| Google News query | `https://news.google.com/rss/search?q=professional+cycling&hl=en-US&gl=US&ceid=US:en` | 100 | No key. Headline and snippet only, no categories, mixed-quality sources. Useful as breadth, poor for deriving anything. |

## Checked and rejected

| Source | Why not |
| --- | --- |
| **BikeRadar** `/feed` | 404. No RSS found at the obvious paths. |
| **UCI** (`uci.org`) | No RSS at `/rss`, `/news/rss`, or `/api/rss/news` — all 404 into the site's HTML. The governing body would be the natural source for embargoed calendar and regulation news, and it does not offer one. |
| **Reddit** `r/peloton/.rss` | 403 to a plain client. Reddit now gates feed access; not worth an auth dance for a demo. |
| **CyclingTips** | Shut down and folded into Velo. `cyclingtips.com/feed/` still returns 25 items — they are Velo's, so adding both double-counts. |
| **Rouleur** | 429 on the Shopify atom feed. Rate-limited on the first request, so unreliable. |
| **GDELT** | Works, and its docs ask for one request every five seconds. Fine for a batch job, wrong for something a page load hits. |
| **ProCyclingStats** | The best structured race and startlist data anywhere, and no public API. Scraping it for a public demo repo is not something to ship. |
| **Wikipedia / Wikimedia REST** | Both work, no key, and are genuinely openly licensed (CC BY-SA). Wrong shape for a *news* stream — categories like `2026 in road cycling` are encyclopaedic and lag the news by days. Worth revisiting for race-calendar and startlist metadata, where lag does not matter and the licence is unambiguous. |

## Licensing

Reading a public RSS feed is not the same as being licensed to republish what it contains. What this plugin does — put a headline and standfirst in front of an editor as a prompt, attribute the source in the seed, and commission original copy — is the ordinary use those feeds exist for.

What would not be fine is passing the full `<content:encoded>` body into a draft and publishing the result. The plugin does not read that field at all, and the reason is this paragraph rather than an oversight.

Escape Collective and INRNG are small and member-funded. If you point the source at them, the attribution in the seed is the least of what you owe them.
