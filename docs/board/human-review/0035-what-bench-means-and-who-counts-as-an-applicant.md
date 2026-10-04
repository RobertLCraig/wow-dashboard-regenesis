# What "bench" means in Regenesis, and who counts as an applicant

## What I need from you

**Two answers.**

1. Bench: is there an in-game rank or a Discord role that means "bench"? If yes, give its exact
   name. If no, say "no bench".
2. Applied: should the pipeline show **every** new-recruits form, or only people who say they want
   to raid?

---

**On 1.** Pass is a rank or role name copied exactly as it shows in game or in Discord, or the
words "no bench". Fail is "officers just know who is benched". Nothing can read that, so the
stage would stay empty.

**On 2.** Pass is "all" or "raiders only". The recruit form has a "reason for joining" answer. The
code's own example of one is "Guild/Social". If you pick raiders only, also say which answers mean raiding,
if you know them.

**Why it needs you.** Both are about how this guild works. The app has no idea of "bench" at all,
and only you know what the recruit form's answers mean in practice.

## Why
`0011` decided on a trial pipeline page with five stages: apply, trial, raider, bench, alumni.
Four of them come from data the app already has. Bench does not. "Bench" shows up only as a
sign-up choice on a single Raid-Helper event, which is one raid, not where a person stands.

The Applied stage reads the new-recruits forum posts. Those include people joining only to be
social, so showing all of them may bury the raid applicants.

## Links

**Relates to**
- `0011` - decided on the pipeline page with a bench stage, and this card settles what bench is.
- `0034` - builds the page without bench. Your answer adds bench to it, or drops it for good,
  and may narrow its Applied column.

## Options

**For question 1, bench:**
1. **A rank or role you name becomes "bench".** It is mapped at `/admin/teams` the same way the
   trial ranks already are, and the page grows a Bench column. Cost: one more card of building,
   and one more line officers keep right on `/admin/teams`.
2. **No bench stage.** The page keeps four stages. Cost: someone who sits out is shown as a
   raider until they leave the raid team.

**For question 2, applied:**
1. **Every form.** Cost: social joiners fill the column, and you scroll past them.
2. **Raiders only.** Cost: a form whose answer is worded oddly is missed. Nobody sees it on this
   page, only in Discord.

## Recommendation
Question 1: **option 1 if the rank or role already exists**, because it is then nearly free.
**Option 2 if it does not**, because a bench mark kept by hand is the "state machine to keep
honest" that `0011` called the cost of this page.

Question 2: **raiders only.** The page is for raid recruitment, and the roster already shows social
members.

Paste this into `## Comments`, with your answers:

    **2026-10-04** **Decided:** Bench: <rank or role name / no bench>. Applied: <all / raiders only, and the form answers that mean raiding>.

## Comments
