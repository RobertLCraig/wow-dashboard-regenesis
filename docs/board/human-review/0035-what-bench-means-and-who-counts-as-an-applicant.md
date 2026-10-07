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
