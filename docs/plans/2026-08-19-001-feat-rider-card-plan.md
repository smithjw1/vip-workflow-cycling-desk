# Rider card — implementation plan

Issue: #4 — "Add a rider card."

## Problem frame

Every cycling story is about one or more riders, and every writer re-types the
same five facts about them (current team, nationality, date of birth,
discipline, a notable result or two). The ones in a hurry get them wrong.

There are two separable halves:

- **The card** — a block that renders rider facts that were already fetched
  and stored on the post. It never makes an outbound request on render.
- **The stage** — the ideation-side decision that a story needs a card for
  a named rider, captured at commission time, before the post exists.

The fetch happens once, at commission, driven by what the desk typed at
ideation. Everything after that is reading what was stored.

This plan does not implement any application code. It exists to be reviewed
and approved (`/approve-plan`) before that happens.

## Requirements (from the issue's acceptance criteria)

1. Facts come from Wikidata only, over `wp_remote_get()`, keyless.
2. Rider facts are fetched once and stored on the post, not fetched on
   render. A page view of a post containing the card makes no outbound HTTP
   request.
3. A missing or ambiguous rider produces no card and no fabricated fields —
   never a nearest-match rider.
4. The card degrades to nothing visible if the stored facts are absent, on
   both the editor and the front end.
5. Tests pass (the plain-PHP style this repo uses; there is no PHPUnit).
6. `php -l` clean, and the existing `find | xargs php -l` / sequence
   validation / prompt-mapper test in CI keep passing.

## Scope boundaries

**In scope**

- A dynamic Gutenberg block (`workflow-discovery-cycling/rider-card`), its
  PHP render, and how the facts it renders are stored on the post.
- A Wikidata lookup class (WordPress layer, `wp_remote_get()`) and a pure
  class that maps its JSON responses into the card's fields — mirroring the
  existing `Feed_Reader` / `Prompt_Mapper` split.
- The ideation-side bit that lets an author say which rider(s) a story needs
  a card for, and the commissioning-time code that turns that into stored
  facts and an inserted block.
- A `wp workflow-cycling` CLI subcommand to exercise the lookup outside of
  ideation, on the precedent of `stream` — this repo has no WP test runner,
  so this is the substitute a human uses to verify the WP-layer code.
- Small doc updates (README, `CLAUDE.md` structure table) so the map of the
  repo stays accurate.

**Out of scope**

- ProCyclingStats (no public API — already rejected in `docs/SOURCES.md`).
- Wikipedia prose or biography text.
- Changing the *existing* six fields on the Cycling Desk sequence
  (`story_type`, `race`, `embargo_state`, `embargo_until`, `sub_editor`,
  `seo_notes`) — their keys, types, options and `show_in_ideation` values
  are untouched. See the note on the new `riders` field below; adding a
  field is treated as distinct from changing the existing ones, but it is
  flagged for reviewer sign-off since the issue text is terse on this point.
- Rider photographs.
- Any UI for correcting or overriding a resolved rider's facts by hand —
  the whole point is that the facts come from one source. If Wikidata is
  wrong, the fix is at Wikidata, not a local override field.
- Notifying the desk when a typed rider name fails to resolve. The mapper's
  existing convention (`Prompt_Mapper::race_from_title`, etc.) is to return
  empty and say nothing rather than raise a flag; this plan follows the same
  convention for consistency. Worth a follow-up issue if it proves confusing
  in practice, not part of this one.

## Assumptions and risks that need reviewer confirmation

This repo's own technical note applies to whoever implements this plan too:
**the VIP Workflows core source is not readable from CI.** Two designs below
depend on core behaviour this plan cannot verify against source:

1. **How a post records which sequence it belongs to.** Unit 3 needs to
   confirm a post is on the Cycling Desk sequence before it acts. The plan
   uses `Sequence_Installer::id()` plus checking for the sequence's own
   metadata (`story_type`/`race` postmeta already being set) rather than
   guessing at an undocumented core postmeta key. If that turns out to be
   unreliable, the fallback is to no-op rather than guess — a story that
   never gets a rider card is a smaller failure than one that gets the
   wrong one attached to some other sequence's post.
2. **The shape of `vip_workflow_ideation_draft_actions`' `callback` field.**
   This plugin's existing action (`cycling-commission`) leaves `callback`
   null and relies on the built-in draft-writing flow; nothing in this repo
   exercises what arguments a non-null callback receives. Rather than guess
   the signature, this plan triggers the rider lookup off `save_post` for
   the created draft (Unit 3), guarded so it only runs once. This is a
   deliberate choice to avoid depending on an unreadable, unstable core
   signature — **a human with access to the `feat/sequence-ideation-draft-actions`
   branch should confirm this is acceptable**, or that using the action's
   own `callback` would be preferable, before or during implementation.

If either assumption is confirmed wrong during implementation, the fallback
described above applies rather than a guess.

## Key technical decisions

**Storage: block attributes, not new post meta.** Facts are written directly
into the block's attributes when it is inserted into the post content
(`riderQid`, `name`, `team`, `nationality`, `dateOfBirth`, `discipline`,
`notableResults`). This keeps a rider card self-contained — no coordination
between a meta key and a block instance, no orphaned meta if an editor
deletes the block, and it matches how Gutenberg blocks normally carry
computed data. The render callback reads only `$attributes`; it never queries
anything at render time, which is what makes "no outbound HTTP on page view"
true by construction rather than by discipline.

**Resolution is two-stage and both stages can end in "no card."**

- *Stage A — exact label/alias match.* Search Wikidata
  (`wbsearchentities`) for the typed name. Keep only candidates whose label
  or an alias is an exact, case-insensitive match for what was typed. If
  that leaves anything other than exactly one candidate, stop: no card.
  This is where a misspelling (`Pogacar` for `Pogačar`) or a fuzzy match
  gets refused, on purpose — the issue is explicit that a fuzzy match is a
  wrong card, and this repo's whole heuristic philosophy
  (`Prompt_Mapper`) already treats "no evidence" as a better outcome than
  a guess.
- *Stage B — is this candidate actually a cyclist.* Fetch the one
  candidate's entity (`wbgetentities`) and require an occupation (`P106`)
  or sport (`P641`) claim that maps to cycling. This exists because a
  common name can have exactly one Wikidata entry and still be the wrong
  person (a musician, not a rider). If it doesn't check out: no card.

Both stages fail closed. Nothing downstream of them ever falls back to "the
most likely value."

**Fields are independently optional; the whole rider is not.** Once a rider
resolves, each of the five facts (team, nationality, DOB, discipline,
notable results) is read straight off whatever statements exist and left
blank if the statement doesn't exist — a retired rider has no "current"
team (a `P54` claim with no end-time qualifier), and that is reported as
blank, not as their last team. The "no fabricated fields" requirement is
about not inventing an answer to a question Wikidata didn't answer, not
about requiring every field to be present before the card renders. The
"degrades to nothing" requirement is about the *whole card* having nothing
to show — handled by checking `name` (the one fact resolution guarantees)
is non-empty before rendering anything at all.

**Caching: two different caches for two different things, only one of which
is new.**

- The render path has no cache because it has no fetch at all (see storage,
  above).
- The *lookup* path — search, entity, and results queries — gets a 7-day
  (`604800`s) transient cache keyed by normalised rider name / QID. Rider
  facts change on the scale of weeks (transfer windows, not news cycles),
  unlike the feed reader's 15-minute cache, which exists because news
  breaks in bursts. Seven days means a transfer that happened yesterday
  might not show up in a card commissioned today, but it will within the
  week, and it means the desk commissioning three stories about the same
  rider in a week triggers one Wikidata round-trip, not three. This cache
  only affects the next lookup — it has no effect on facts already written
  into a published card's block attributes.

**Notable results, one query, capped at two.** A rider's Wikidata item
doesn't carry "notable results" as a single statement; it's the reverse of
races' own "winner" (`P1346`) claims. One SPARQL query per rider
(`query.wikidata.org/sparql`), also cached for 7 days by QID, returns
event + point-in-time pairs; the pure mapper formats the two most recent as
strings ("Winner, 2024 Tour de France") and returns an empty array if there
are none. No SPARQL query, no notable-results text — the card just omits
that row, per the "fields are independently optional" decision above.

**The `riders` field is new, additive metadata on the sequence, asked at
ideation.** A plain `text` field (comma-separated names), `required: false`,
`show_in_ideation: true`, added to `sequences/cycling-desk.json`. This is
the only mechanism this codebase has for asking something at commissioning
time — it's exactly what the four existing `show_in_ideation` fields already
do — so building a parallel mechanism would be inventing a second one for no
reason. It is additive: the six existing fields keep their keys, types,
options and ideation flags exactly as they are. Flagged above for reviewer
sign-off given how tersely the issue's scope line reads.

**No build tooling for the block's editor UI.** The edit view uses
`wp.serverSideRender` (`ServerSideRender`) pointed at the block's own PHP
render callback, built with `wp.element.createElement` calls rather than
JSX, enqueued as a plain script with `wp-blocks`/`wp-element`/`wp-server-side-render`
dependencies. `save` returns `null` — this is a fully dynamic block, so
there's no client-rendered markup to keep in sync with the PHP. This is also
why "degrades to nothing in the editor" falls out for free: the editor
preview and the front end run the exact same PHP function.

**Wikidata fixtures are real captures, not invented JSON.** Following
`docs/SOURCES.md` / `tests/fixtures/feed-items.json`'s precedent exactly:
before writing `Rider_Mapper`'s tests, capture real `wbsearchentities` and
`wbgetentities` responses (a current pro rider, a retired rider, an
ambiguous multi-candidate search, and a same-name non-cyclist) into
`tests/fixtures/`, dated in a comment the way `SOURCES.md` dates its feed
capture. Every edge case the mapper guards against should come from one of
these, not from a hand-written response shape.

## Implementation units

### Unit 1 — Wikidata client (WordPress layer)

**Goal:** fetch raw Wikidata JSON over `wp_remote_get()`, with the 7-day
lookup cache. No fact-shaping here — that's Unit 2.

**Files:** `workflow-discovery-cycling/includes/class-wikidata-client.php`

**Approach:**
- `search( string $name ): array|WP_Error` → `wbsearchentities`
  (`language=en`, `type=item`), transient-cached 7 days keyed by
  `md5( strtolower( trim( $name ) ) )`.
- `entity( string $qid ): array|WP_Error` → `wbgetentities`
  (`props=labels|claims`), cached 7 days keyed by QID.
- `victories( string $qid ): array|WP_Error` → SPARQL query against
  `query.wikidata.org/sparql` for reverse `P1346` claims, cached 7 days
  keyed by QID.
- Shared private `request()` helper: sets a descriptive `User-Agent` (per
  Wikimedia's API etiquette), a short timeout, and returns `WP_Error` rather
  than throwing on a non-200 or unparsable body — a Wikidata outage should
  behave like a feed outage (Unit 3 just skips the rider, doesn't fail the
  whole commission).
- Constant `CACHE_TTL = 604800` with the reasoning above in the docblock,
  same style as `Feed_Reader::CACHE_TTL`.

**Test scenarios:** none directly — this class touches the network and
WordPress transients, neither available to the plain-PHP test runner. Unit
5's CLI command is the manual verification path.

### Unit 2 — Rider mapper (pure, no WordPress)

**Goal:** turn raw Wikidata JSON into either "no card" or a fully-formed set
of card fields. This is the class with the guessing in it, so it's the class
with the test — same reasoning `CLAUDE.md` already gives for
`Prompt_Mapper`.

**Files:**
- `workflow-discovery-cycling/includes/class-rider-mapper.php`
- `workflow-discovery-cycling/tests/test-rider-mapper.php`
- `workflow-discovery-cycling/tests/fixtures/wikidata-search-*.json`,
  `wikidata-entity-*.json`, `wikidata-victories-*.json`

**Approach:**
- `resolve_candidate( array $search_response, string $typed_name ): ?string`
  — Stage A. Exact case-insensitive match against label or any alias; null
  unless exactly one candidate survives.
- `is_cyclist( array $entity_claims ): bool` — Stage B. True only if a
  recognised cycling occupation (`P106`) or sport (`P641`, `Q5386`) claim
  is present.
- `facts_from_entity( array $entity_claims, array $labels ): array` —
  returns `[ 'name' => ..., 'team' => ..., 'nationality' => ...,
  'date_of_birth' => ..., 'discipline' => ... ]`, each `''` if the source
  statement is absent. Team logic: prefer a `P54` claim with no `P582`
  (end time) qualifier; if none is "current," leave blank rather than
  reporting a past team as current.
- `notable_results( array $victory_bindings ): array` — up to two most
  recent, formatted `"Winner, {year} {event label}"`.
- `map( string $typed_name, array $search_response, ?array $entity,
  ?array $victories ): ?array` — the one entry point Unit 3 calls; wires
  the above together and returns `null` at any fail-closed point.

**Test scenarios (against real fixtures, exact-value asserts, same style
as `test-prompt-mapper.php`):**
- A typed name matching exactly one cyclist candidate resolves; facts match
  the fixture exactly.
- A typed name with a diacritic dropped (`Pogacar`) does not exactly match
  the fixture's label/alias and resolves to `null` — the intended,
  precision-over-recall failure mode.
- A search returning multiple exact-label candidates (a common name)
  resolves to `null`.
- A search whose sole exact-label candidate fails the `is_cyclist` check
  (same-name non-cyclist fixture) resolves to `null`.
- A retired rider's entity has no current-team claim → `team` is `''`,
  not their last team.
- An entity with no recognised discipline claim → `discipline` is `''`.
- No victory bindings → `notable_results()` returns `[]`.
- `map()` end-to-end against each fixture set, asserting the full returned
  array or `null`.

### Unit 3 — Commissioning: the `riders` field and the fetch-once hook

**Goal:** the ideation-side stage. An author types rider name(s) at
commission time; once the draft exists, each name is resolved at most once
and, on success, a rider card block is appended to the post with its
attributes filled in.

**Files:**
- `workflow-discovery-cycling/sequences/cycling-desk.json` (add the
  `riders` field)
- `workflow-discovery-cycling/includes/class-rider-card-commissioner.php`
  (new)
- `workflow-discovery-cycling/workflow-discovery-cycling.php` (wire the
  `save_post` hook in `Plugin::boot()`)

**Approach:**
- Add to `metadata_fields`:
  ```json
  {
    "key": "riders",
    "label": "Riders",
    "type": "text",
    "required": false,
    "searchable": true,
    "show_in_ideation": true
  }
  ```
- `Rider_Card_Commissioner::on_save( int $post_id, \WP_Post $post ): void`,
  hooked to `save_post`, bails out fast and cheaply for the common case
  (autosave, revision, not the cycling-desk sequence, already processed)
  before doing anything else:
  1. Bail on autosave/revision, same guard style WordPress plugins always
     use.
  2. Bail unless `get_post_meta( $post_id, '_workflow_cycling_riders_processed', true )`
     is empty.
  3. Bail unless the post looks like a Cycling Desk post — checked via
     `Sequence_Installer::id()` plus the presence of this sequence's own
     metadata on the post (see the risk noted above; no-op rather than
     guess if this signal turns out wrong).
  4. Set the guard meta to `1` **before** doing any network work or calling
     `wp_update_post()` — this is what makes the hook safe to re-enter
     (the `wp_update_post()` call below re-fires `save_post`) and is what
     makes the fetch happen *once* rather than on every subsequent save.
  5. Read the `riders` field value, split on commas, trim, dedupe, cap at
     5 names (bounds worst-case request count per commission).
  6. For each name: `Wikidata_Client::search()` →
     `Rider_Mapper::resolve_candidate()`; if null, skip this name entirely
     (no card, no partial data, nothing written). Otherwise fetch entity +
     victories, map to facts, and append
     `serialize_block( [ 'blockName' => 'workflow-discovery-cycling/rider-card',
     'attrs' => $attributes ] )` to a buffer.
  7. If the buffer is non-empty, append it to `$post->post_content` and
     `wp_update_post()` once (a single update for however many riders
     resolved, not one per rider).
- No error is surfaced anywhere if every name fails to resolve — consistent
  with the scope decision above.

**Test scenarios (manual/integration — no WP test runner in this repo):**
- Commission with one resolvable rider name → post content gains exactly
  one rider-card block with the fixture's facts as attributes.
- Commission with a misspelled/ambiguous name → post content gains no
  block, and the guard meta is still set (so it isn't retried on every
  save).
- Commission with two resolvable names → two block instances, correct
  attributes each.
- A second, unrelated save on the same post (e.g., the reporter edits the
  body) does not trigger a second Wikidata round-trip — verified by
  checking the guard meta short-circuits before any client call.
- A post on a different sequence (or no sequence) is untouched.
- Wikidata search/entity/victories each independently returning a
  `WP_Error` results in that rider being skipped, not the save failing.

### Unit 4 — The block

**Goal:** render whatever facts are on the block's attributes; render
nothing if they're absent. Same behaviour in the editor and on the front
end, driven by the same PHP.

**Files:**
- `workflow-discovery-cycling/blocks/rider-card/block.json`
- `workflow-discovery-cycling/blocks/rider-card/render.php`
- `workflow-discovery-cycling/blocks/rider-card/edit.js`
- `workflow-discovery-cycling/blocks/rider-card/style.css` (minimal, plain
  CSS)
- `workflow-discovery-cycling/includes/class-rider-card-block.php` (new;
  registers the block type + enqueues `edit.js` with
  `enqueue_block_editor_assets`)

**Approach:**
- `block.json`: `apiVersion: 3`, name
  `workflow-discovery-cycling/rider-card`, attributes for `riderQid`,
  `name`, `team`, `nationality`, `dateOfBirth`, `discipline`,
  `notableResults` (array of strings), all with sensible empty defaults;
  `"save": null`-equivalent (dynamic block, PHP-rendered).
- `render.php`: reads `$attributes['name']`; returns `''` immediately if
  it's empty or missing — this single check is what "degrades to nothing
  visible" reduces to. Otherwise renders a small definition-list-style
  panel with whichever of the five facts are non-empty (each is checked
  independently — a card with four facts and a blank discipline just omits
  that row).
- `edit.js`: no JSX, `wp.element.createElement`, wraps
  `wp.serverSideRender` pointed at the block name and current attributes.
  No controls to edit the facts by hand (see scope boundaries).
- Registration: `register_block_type( __DIR__ . '/../blocks/rider-card', [ 'render_callback' => ... ] )`
  called from `Plugin::boot()` via `init`.

**Test scenarios (manual — this is presentation code, verified by loading
WP, not by the plain-PHP runner):**
- Insert the block with a full attribute set (e.g., via Unit 5's CLI output
  copied into a test post, or directly in the editor) → all five facts
  render, front end and editor.
- Block with `name` empty/missing → renders nothing, front end and editor
  (check the rendered HTML has no wrapper element at all, not an empty
  one).
- Block with `name` present but `discipline` empty → four rows render, no
  empty discipline row.

### Unit 5 — CLI diagnostic

**Goal:** a way to exercise Units 1–2 by hand, since this repo has no WP
test runner and the rider pipeline is otherwise only reachable through the
ideation UI.

**Files:** `workflow-discovery-cycling/includes/class-cli.php` (extend)

**Approach:** `wp workflow-cycling rider-lookup <name>` — runs
search → resolve → entity → facts → notable results and prints either the
resolved facts as a table or a clear "no confident match" message, on the
precedent of `stream` separating "feed is down" from "mapping dropped
everything." `[--force]` to skip the 7-day cache, matching `stream`'s flag.

**Test scenarios:**
- `wp workflow-cycling rider-lookup "Tadej Pogačar"` prints resolved facts.
- `wp workflow-cycling rider-lookup "Pogacar"` prints "no confident match."
- `wp workflow-cycling rider-lookup "Tadej Pogačar" --force` bypasses the
  cache (observable via a fresh `wp_remote_get` timestamp/log, or simply by
  flushing and confirming it still works with no stale transient).

### Unit 6 — Docs

**Goal:** keep the repo's own map of itself accurate.

**Files:** `README.md`, `CLAUDE.md`

**Approach:** add rows to both structure tables for the new files
(`class-wikidata-client.php`, `class-rider-mapper.php`,
`class-rider-card-commissioner.php`, `class-rider-card-block.php`,
`blocks/rider-card/`), one sentence each in the same voice as the existing
rows. Add a short paragraph to README's "How the three pieces fit" section
describing the fourth piece (the card). No change to `docs/SOURCES.md` —
Wikidata's suitability for exactly this ("race-calendar and startlist
metadata, where lag does not matter") is already noted there from the
source-selection work; this plan doesn't need to re-litigate it.

## Test plan summary

- `php workflow-discovery-cycling/tests/test-prompt-mapper.php` — unchanged,
  must keep passing (the `riders` field addition to `cycling-desk.json`
  doesn't touch anything this test asserts).
- `php workflow-discovery-cycling/tests/test-rider-mapper.php` — new, run
  the same way, exit non-zero on any failed assertion.
- `find . -name '*.php' -print0 | xargs -0 -n1 php -l` — must stay clean
  across every new file.
- CI's sequence-validation step (`ci.yml`) re-counts `show_in_ideation`
  fields after the `riders` field is added; still expected to pass since
  it only asserts `count( $ideation ) > 0`.
- Everything else (Units 3–6) is verified by hand, per the test scenarios
  listed against each unit, since this repo has no WordPress test runner.
