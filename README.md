# Cycling Desk — a VIP Workflows extension

A discovery source that pulls cycling news from three publisher feeds, paired with a write-edit-publish sequence that asks for its commissioning metadata **during ideation** rather than after the post exists.

The point of the pairing is the second half. VIP Workflows lets a sequence flag a metadata field `show_in_ideation`, and lets a discovery source name the sequence its items are heading for. Together those mean an editor picking a story off the stream is asked what kind of item it is and which race it belongs to at the moment they decide — not in the editor sidebar afterwards, once the decision has already been made in their head.

This repo exists to demonstrate that, and to be a working example of a real extension built against the public extension points.

## What's in here

| Path | What it is |
| --- | --- |
| `workflow-discovery-cycling/` | The plugin. One self-contained WordPress plugin, no build step. |
| `workflow-discovery-cycling/sequences/cycling-desk.json` | The Cycling Desk sequence — write → edit → publish, with the metadata fields. |
| `workflow-discovery-cycling/tests/` | Tests for the mapper, plus a real feed capture as a fixture. |
| `workflow-assistant-rider-bios/` | A sibling plugin: a research ability that returns short rider bios from Wikipedia for every rider named in a story. |
| `.github/` | The [agentic workflow template](https://github.com/whyisjake/agentic-workflow-template) — agent-ready issues, routing, planning gate. |
| `docs/SOURCES.md` | The feeds, what each is good for, and what was tried and rejected. |
| `docs/DEMO.md` | The walkthrough. |

## Rider Bios (Wikipedia)

`workflow-assistant-rider-bios/` is an add-on alongside the Cycling Desk source, not a change to it. It registers a VIP Workflows **research ability** — a different extension point from the discovery source above — that a desk can run against a draft to get a short bio for every rider it names: nationality, current team, and notable and recent wins, each read from Wikipedia's public API (no key, no rate limit — see `docs/SOURCES.md`).

It works in two stages, the same split as the discovery source's Feed_Reader and Prompt_Mapper:

1. `Rider_Extractor` (no WordPress) reads candidate names out of the story text by shape — two to four capitalised words, allowing a lowercase surname particle like 'van' or 'de' in the middle — and rejects anything that reads like a race, team or section heading instead of a person.
2. `Wikipedia_Client` looks each candidate up, and `Bio_Mapper` (no WordPress) decides whether the resolved page is confidently about a cyclist at all. A candidate that does not resolve to one specific, confirmed cyclist is dropped rather than returned with guessed-at fields — the same "a wrong hint is worse than no hint" rule the discovery source runs on.

This means the extractor can afford to be a little generous about what looks name-shaped — a bike model or a race name that happens to fit the same capitalisation pattern gets filtered out at the Wikipedia stage, not returned as somebody's bio. A rider referred to by surname alone in a headline ('Vollering', 'Pogačar') is not picked up; that trade is deliberate, in keeping with this repo's low-recall-by-design heuristics.

## Requirements

- **VIP Workflows**, on a branch that has the ideation-metadata work. At the time of writing that is `feat/sequence-ideation-draft-actions` in `Automattic/vip-workflows` — **not `main`**. The three extension points this plugin uses (`vip_workflow_discovery_sequence`, `show_in_ideation`, `vip_workflow_ideation_draft_actions`) do not exist on `main` yet.
- The **ideation** experiment enabled.
- PHP 8.2+.

Without the branch, the discovery source still works and still returns cycling stories — you just get no metadata section and the default button, because nothing is there to read the sequence off the item.

## Install

```sh
# Into a wp-content/plugins directory, or a VIP client-mu-plugins checkout.
git clone git@github.com:smithjw1/vip-workflow-cycling-desk.git
```

Activate `Workflow Discovery: Cycling Desk`. Activation installs the sequence, but only if VIP Workflows was already active — plugin activation order is not something a plugin gets to choose. If the sequence is missing:

```sh
wp workflow-cycling install-sequence
```

Then check what the feeds actually returned, which is the fastest way to tell a network problem from a mapping one:

```sh
wp workflow-cycling stream
wp workflow-cycling stream --force --limit=40   # refetch
wp workflow-cycling flush                        # drop the 15-minute cache
```

## How the three pieces fit

**The source names the sequence.** Every prompt the source returns carries `blueprint_id`, resolved by slug at request time. Resolving by slug and not id matters: ids differ per site, and a hard-coded one would point at whatever sequence happens to hold that id — which is worse than pointing at nothing, because ideation would then ask for another desk's fields.

**The sequence decides what gets asked.** Four of its six metadata fields are flagged `show_in_ideation`:

| Field | Asked at ideation | Why |
| --- | --- | --- |
| Story Type | yes | The commission *is* this decision. A race report and an obituary off the same crash are different assignments. |
| Race | yes | Known before the post exists; it is what the desk files under. |
| Embargo | yes | Whether a story is embargoed governs what happens to it from the first minute, not from the sub's pass. |
| Embargo Until | yes | Useless separately from the field above — see the action, below. |
| Sub-editor | no | Not known at commissioning. Assigned when the desk picks it up. |
| SEO Notes | no | Written against copy that does not exist yet. |

The four asked at ideation still live on the post and stay editable there. Flagging a field moves *when it is asked*, not where it lives.

**The sequence's action carries the rules that span fields.** The plugin registers a `cycling-commission` action through `vip_workflow_ideation_draft_actions`. It uses the built-in draft-writing flow — this is not a different way of making a post — and adds one check: a story marked `Embargoed` with no time on it is refused, and so is one whose time has already passed.

That check has to live on the action because nothing above it can see the whole field set. The fields are defined per sequence, so only the action knows that `embargo_state` and `embargo_until` are a pair. An embargo with no time reads as handled — somebody set the field — and then nothing stops it going out, because there is no time for anything to compare against.

## What the source derives, and what it refuses to

The feeds carry more than a headline. A race is often named in the headline prefix (`Volta a Portugal: …`), and the publisher's own category often implies the kind of item. The mapper reads both out and writes them into the **seed text**, framed as hints to confirm.

It does not prefill the metadata fields, for two reasons. Nothing in ideation prefills a field today — there is no hook for it, which is [worth raising upstream](#upstream-gaps). And more importantly, a guess in a required field is worse than an empty one: `Volta a Portugal:` in a headline is strong evidence about the race and no evidence at all about whether the desk wants a report or an obituary. The hint belongs in front of the person making the call, not in the box instead of their answer.

The heuristics were tuned against a real capture of all three feeds, kept in `workflow-discovery-cycling/tests/fixtures/feed-items.json`. On that capture the race detector fires on 11 of 60 items and is right on all 11. Low recall, deliberately — see `docs/SOURCES.md` for what it rejects and why.

## Tests

No PHPUnit, no WordPress, no build step:

```sh
php workflow-discovery-cycling/tests/test-prompt-mapper.php
php workflow-assistant-rider-bios/tests/test-rider-extractor.php
php workflow-assistant-rider-bios/tests/test-bio-mapper.php
```

CI runs all three plus `php -l` over everything on 8.2 and 8.3.

## Upstream gaps

Things this extension ran into that VIP Workflows might want:

1. **No way to prefill an ideation metadata field.** The source knows the race and the likely story type and has nowhere to put them except the seed prose. A filter on the field set — defaults a provider can suggest and the editor can overwrite — would close it. The framing matters: suggestions, visibly suggested, not silent defaults.
2. **No signal that a discovery source's search is local.** These feeds have no query endpoint, so search filters the cached items, which is roughly a day of coverage. An editor who searches a race from last month and gets nothing will conclude it was never covered.

## Licence

GPL-2.0-or-later, matching WordPress and VIP Workflows.

Feed content belongs to its publishers. This reads public RSS to put headlines and standfirsts in front of an editor as story prompts — it does not republish them, and the drafts it commissions are original copy with the source attributed. If you point it at other feeds, check their terms.
