# vip-workflow-cycling-desk — agent context

A VIP Workflows extension: a cycling news discovery source paired with a write-edit-publish sequence that asks for its commissioning metadata during ideation. Read `README.md` for what it does and why; this file is the working constraints.

**This repo is public.** No customer names anywhere in it — describe the use case instead.

## Where the real code is

This is a standalone plugin. The plugin it extends lives at `~/projects/vip-workflows/` (monorepo: core `vip-workflow/` plus `workflow-*` extensions). When a question is about how an extension point behaves, go read it there — do not answer from this repo.

The three extension points used here are on branch **`feat/sequence-ideation-draft-actions`**, not `main`:

| Point | Where |
| --- | --- |
| `vip_workflow_discovery_sequence`, and `blueprint_id` on a discovery item | `vip-workflow/includes/api/class-discovery-controller.php` |
| `show_in_ideation` on a metadata field | `vip-workflow/includes/blueprints/class-blueprint.php` → `get_ideation_metadata_fields()` |
| `vip_workflow_ideation_draft_actions` | `vip-workflow/includes/ideation/class-draft-actions.php` |

Also worth reading: `includes/ideation/class-ideation-sequence.php` for how a sequence attaches to a project, and `workflow-discovery-test/` and `workflow-discovery-foresight/` in the monorepo as the reference provider shapes.

## Structure

| Path | What |
| --- | --- |
| `workflow-discovery-cycling/workflow-discovery-cycling.php` | Provider registration, the `cycling-commission` draft action, the embargo rule. |
| `includes/class-feed-reader.php` | Fetches and normalises the three RSS feeds. All the WordPress in the project is here and in the main file. |
| `includes/class-prompt-mapper.php` | Feed item → discovery prompt, plus the race and story-type heuristics. **No WordPress**, deliberately — it holds all the guessing, so it must stay testable without a WP install. |
| `includes/class-sequence-installer.php` | Installs and resolves the sequence. Resolves by **slug**, never by id. |
| `includes/class-cli.php` | `wp workflow-cycling install-sequence` / `stream` / `flush`. |
| `sequences/cycling-desk.json` | The sequence. Schema matches `vip-workflow/includes/database/class-seeder.php` — statuses need `status` and `region_entry`, not just `key`/`label`. |
| `tests/` | Plain-PHP assertions, no PHPUnit. |

## Constraints

- **No build step, no composer, no npm.** One self-contained plugin directory. This is a deliberate match for how the `vip-workflow-extensions` shelf works.
- **`Prompt_Mapper` stays free of WordPress functions.** It is the only class with a test, because it is the only class that guesses.
- **Heuristics get tuned against the fixture, never against invented headlines.** `tests/fixtures/feed-items.json` is a real capture of all three feeds from 14 August 2026. Every false positive the code guards against came out of it; none was a shape anyone would have written by hand. If you widen a heuristic, re-run the test — it asserts the exact set of races found, not a count, so drift shows up.
- **A wrong hint is worse than no hint.** The mapper returns empty rather than falling back to the most likely value, and the seed frames what it did derive as something to confirm. Precision over recall: on the fixture the race detector fires on 11 of 60 and is right on all 11. Do not "improve" recall without checking precision on the fixture.
- **Never prefill a metadata field.** There is no hook for it, and it is the wrong shape anyway — see the README's upstream-gaps section. Suggestions go in the seed, visibly suggested.
- Feed content belongs to its publishers. The plugin deliberately does not read `<content:encoded>`. See `docs/SOURCES.md`.

## Testing

```sh
php workflow-discovery-cycling/tests/test-prompt-mapper.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

`xargs`, not `find -exec` — find exits 0 even when the command it ran failed, so `-exec php -l` reports a syntax error as a pass. CI does it the working way.

## Agentic workflow

`.github/` is the [agentic workflow template](https://github.com/whyisjake/agentic-workflow-template): agent-ready issue template, auto-labelling, complexity-aware routing, and a plan-approval gate on `complexity:high`. Testing that template is half the reason this repo exists, so prefer opening an agent-ready issue over editing directly when the change is big enough to be worth one.

Repo variable `AGENT_PROVIDER` defaults to `claude` and needs the `CLAUDE_CODE_OAUTH_TOKEN` secret. Labels come from running Actions → Setup Labels once.

## Notes for the notes directory

Jacob's working notes for VIP Workflows live in `~/french-connection/workflows/`. Anything here that turns out to be core-shaped belongs in that directory's `MERGE-CANDIDATES.md` — in particular the two upstream gaps in the README.
