# What "bench" means in Regenesis, and which recruits the pipeline shows

## What I need from you
1. **Bench:** is there an in-game rank or Discord role that means "bench"? If yes, give its exact name. If not, say "no bench". Pass: a name copied exactly, or "no bench". "Officers just know" cannot be read by the app, so the column would stay empty.
2. **Applicants:** should the trial pipeline page show every new-recruits form, or only people who want to raid? Reply "all" or "raiders only". For raiders only, say which "reason for joining" answers mean raiding, if you know.

**My recommendation:** 1, use the rank or role if one already exists, because then bench is nearly free. If none exists, choose no bench, because a mark kept by hand is one more thing officers have to keep right. 2, raiders only. The page is for raid recruitment, and the roster already shows social members.
Paste to answer: `**2026-10-07** **Decided:** Bench: <rank or role name / no bench>. Applied: <all / raiders only, and the form answers that mean raiding>.`

## What you need to know
- The trial pipeline page (card 0011, built by 0034) tracks people through apply, trial, raider, bench, alumni. The app can fill four of these. It has no idea of bench.
- "Bench" appears in the data only as a sign-up choice on one Raid-Helper event, which is one raid, not where a person stands.
- A named rank or role gets mapped at `/admin/teams`, like the trial ranks, and adds a Bench column. Without bench, someone sitting out shows as a raider until they leave the raid team.
- The Applied column reads the new-recruits forum posts, and those include social joiners ("Guild/Social" is one answer). Showing all of them may bury raid applicants. Showing raiders only may miss an oddly worded form.

## See it
- <https://regenesis.enhanceify.co.uk/admin/teams>
- Local: <https://regenesis.test>

---
## For the agent (Rob can stop reading here)
On a bench name: raise a build card to map it at `/admin/teams` and add a Bench column to the pipeline page. On "no bench": record that bench is dropped for good. On "raiders only": narrow the Applied column to the named answers. Then move to `done/`.

Related: 0011 decided the pipeline page with a bench stage; 0034 built the page without bench (done).

## Comments

**2026-10-11** **Decided:** Bench: no bench. Applied: raiders only. Bench is dropped for good: someone sitting out shows as a raider until they leave the raid team.
**2026-10-11** Checked before acting. No bench rank exists: the GRM SavedVariables (account-wide `Guild_Roster_Manager.lua`, 2026-10-02) hold 18 rank names and none is bench. "Bench" appears only as a Raid-Helper sign-up status and once in one officer note ("Allies friend BENCH"). `TeamMapping::TEAMS` has no bench team. Discord roles are snowflakes mapped at `/admin/teams` and were not listable offline; the officer note is the only sign of bench anywhere.
**2026-10-11** Built in commit `000454b`. `TrialPipelineController::applied()` now drops a form whose `reason_for_joining` matches `guild|social` (case-insensitive), after keeping each character's latest form. A form with no reason stays in, so an oddly worded raid form is not lost. No list of "raiding" answers was given, so nothing else is filtered. Test: `it lists raid applicants under applied and leaves out guild and social joiners`. Suite 800 passed, Pint clean. A browser check on `/roster/pipeline` is still owed: the worktree build cannot prove it in Herd. Moved to ai-review because it produced code.

### 2026-10-13 review (v20261013043234-2ff9)

**suite**

`vendor\bin\pest.bat` exited 0 after 76s, run by this job rather than reported by the card.

**acceptance: sound**

The card has no acceptance block. So I checked it against its "For the agent" promises.

**Promise 1: "no bench", dropped for good.** No code was needed for this. The comment on the card records the decision, and it gives evidence: there is no bench rank in GRM and no bench team in `TeamMapping::TEAMS`. The pipeline page has no Bench column. This promise is met.

**Promise 2: raiders only.** This is in `TrialPipelineController::applied()`. The method keeps only each character's latest form. Then it removes any form whose `form_fields['reason_for_joining']` matches `guild|social`, in any letter case. That key is the one the form data uses. The migration's example value is `"Guild/Social"`, which matches. A form with no reason stays in, so a raid form with odd wording is not lost.

**The test.** `lists raid applicants under applied and leaves out guild and social joiners` is in `tests/Feature/TrialPipelineTest.php`. The suite passes.

**One small risk, not a defect.** Suppose a real raid answer has the word "guild" in it, for example "Raid with the guild". The filter would remove it. Rob named no raid answers, and the only answer on record is `Guild/Social`. So I cannot show that this fails.

I found no promise that the code does not keep.

VERDICT: sound

**scope: sound**

I tried to break this card on scope, and I could not.

**What the card built.** The build commit is `000454b`. It changes only 2 files:
- `TrialPipelineController::applied()`. The new filter drops a form when `reason_for_joining` matches `guild|social`. A form with no reason stays in.
- `tests/Feature/TrialPipelineTest.php`. It adds one test for that filter.

Commit `074db74` changes only the card file.

**The other files in the diff come from other cards.** These are the webhook regex (`0030`), the digest lines (`0031`), the team events (`0033`), and the pipeline page and route (`0034`). Those cards are already done. They are not scope creep by this card.

**Nothing is half done.**
- **"No bench":** the card comment says bench is dropped for good. It also gives the evidence: no GRM rank and no `TeamMapping` team means bench. No bench column or bench mapping was added, and that is correct.
- **"Raiders only":** Rob named no answers that mean raiding. So the builder filters out only the one known social answer, "Guild/Social". It also keeps forms with no reason. This matches the card's warning about oddly worded forms.
- **Card place:** the card is in `ai-review/`, not `done/`. That is the normal path for a card that made code.
- **Browser check:** nobody has checked `/roster/pipeline` in a browser. This is a manual follow-up. It is not a criterion.

This card has no acceptance block, and the agent part asks for nothing more. So I wrote no `ADD:` lines.

VERDICT: sound

**breakage: sound**

I tried to break this change. I could not find a break.

**What I checked:**
- **The filter.** It is in `TrialPipelineController::applied()`. It removes a form only when `form_fields['reason_for_joining']` matches `guild|social`. A form with no reason, or with null `form_fields`, stays in, because `?? ''` handles both. Only one answer is known: `Guild/Social`, from the example in the `create_discord_recruit_forms_table` migration. The filter catches that answer.
- **Order.** `unique()` runs before `reject()`. So the app judges each character by their latest form only. Example: a recruit sends a raid form, and later sends a social form. That recruit leaves the Applied column. This is what the card comment says the build does.
- **Callers.** `applied()` is private. It has one caller, `__invoke()`. No other code reads `reason_for_joining`.
- **Comments.** The class docblock now says "social joiners left out". That is true.
- **Bench.** No code was added for bench, and that is correct for "no bench".

**One risk, not a defect:** the match is a substring. So an answer such as "raiding with the guild" would also be removed. No such answer is known today. If the form has a new answer later, change the regex to an exact match on `Guild/Social`.

This card has no acceptance block. The work keeps what the card asks for.

VERDICT: sound

