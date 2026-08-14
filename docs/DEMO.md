# Demo walkthrough

Eight minutes, and the whole thing is one point: **the sequence decides what ideation asks for, and the source decides which sequence.** Everything else is scaffolding for that.

## Before you start

- VIP Workflows checked out on a branch with the ideation-metadata work — `feat/sequence-ideation-draft-actions` at the time of writing, **not `main`**. Check the branch first; branching off `main` empties the metadata section with no error at all.
- The **ideation** experiment enabled.
- `wp workflow-cycling stream` returning rows. Run it ten minutes ahead, not at the last moment — the first fetch is the slow one, and after it the 15-minute cache is warm.
- If you are demoing on the local dev-env, remember cron is disabled there. Nothing about *this* extension needs a queue runner, but the ideation research phase does.

## The walkthrough

**1. Show the sequence first, not the stream.** Sequences → Cycling Desk. Three stages: Writing → Editing → Published. Open the sequence settings inspector and show the metadata fields, and specifically the `Show in ideation` toggle on four of the six.

Say what the split is: Story Type, Race and the two embargo fields are decisions taken *before the post exists*. Sub-editor and SEO Notes are not — one is assigned when the desk picks it up, the other is written against copy that does not exist yet.

**2. Show the action.** Same inspector: the Create Draft action is set to **Commission Story**, which this plugin registered. Note that the ideation button will read those words. Newsrooms do not agree on whether a commission arrives written or blank, so the sequence says.

**3. Now the stream.** Ideation → Cycling Desk. Real cycling stories from three publishers, filterable by publisher and by what the piece looks like. Pick a race report — `Volta a Portugal: …` or similar, something with a race in the headline.

**4. The moment.** The metadata section is already there, asking for Story Type, Race and the embargo pair — because the item named the sequence, and the sequence named those fields. Nobody assigned a workflow. The button says **Commission Story**.

Compare it directly: type a seed into the box by hand instead. No sequence, so no metadata section and the default button. That absence is the designed answer, not a gap — putting an unasked-for sequence's fields in front of someone is worse than asking them nothing.

**5. The cross-field rule.** Set Embargo to `Embargoed` and leave Embargo Until empty. Commission it. It refuses, and says which of the two to fix.

This is the part worth dwelling on. The check cannot live anywhere above the action, because the fields are defined *per sequence* — only the action sees the whole set and knows those two are a pair. And the failure it prevents is the quiet kind: an embargo with no time reads as handled, because somebody set the field, and then nothing stops it going out because there is no time for anything to compare against.

Set a time in the past. Also refused, for the same reason wearing a value.

**6. Commission it properly.** Set a future time. The draft arrives in the Writing stage of the Cycling Desk sequence with the metadata already on the post — and the fields are editable in the editor sidebar, which is the other half of the design. Flagging a field moves *when it is asked*, not where it lives.

## What to say if asked "did the AI fill that in?"

No, and that is deliberate. The source reads the race out of the headline and the likely story type out of the publisher's category, and writes both into the **seed** as hints framed to confirm — the editor sees "the race looks to be Volta a Portugal" and answers the question themselves.

Two reasons. There is no hook to prefill an ideation field today. And a guess in a required field is worse than an empty one: the headline is strong evidence about the race and no evidence at all about whether the desk wants a race report or an obituary. That distinction was live in the real feed the day this was built.

It is a genuine gap, and it is written down as one in the README.

## If the stream is empty

Three different problems look identical from the ideation screen. `wp workflow-cycling stream` tells them apart:

- **Warns about no active sequence** → the sequence is not installed. `wp workflow-cycling install-sequence`.
- **Errors that no items came back** → outbound HTTP is blocked, or all three publishers are down. Try `--force`.
- **Rows print but ideation shows nothing** → wrong branch of VIP Workflows, or the ideation experiment is off.
