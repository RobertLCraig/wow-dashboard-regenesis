# Where the weekly digest goes

## What I need from you

**Pick 1, 2 or 3 below.** It decides whether the app starts posting to Discord on a schedule with
nobody asking it to, and if so, to which channel.

My recommendation is **1**, the officer channel.

**Pass** is a number in this card, and a channel id if it is 1 or 2.

**Fail** is drifting into 2 rather than choosing it. The digest carries parse rankings, so posting
it guild-wide is a social decision about whether performance data goes where everyone sees it, and
that is not a thing to arrive at by changing a config value.

**Why it needs you** It is a call about the guild's channels rather than about the code. The app
already posts pre-raid reminders, so it can post; what nothing here knows is whether the guild wants
it posting rankings unattended.

## Why
The digest Artisan command produces the weekly summary including WCL parses, and today it only runs
when an officer triggers it. A digest nobody triggers is a digest nobody reads, and the value of a
weekly summary is entirely in its being weekly.

## Options
1. **Auto-post to an officer channel on a schedule.** Cost: a scheduled write to Discord that will
   eventually post something wrong or empty, in public, with no one watching. Needs the empty case
   handled deliberately.
2. **Auto-post to a guild-wide channel.** Same as 1 with a larger audience, and the digest contains
   parse rankings, so it is also a call about whether the guild wants performance data posted where
   everyone sees it.
3. **Stay officer-triggered, but nag.** Schedule a reminder to an officer rather than the digest
   itself. Cost: the least useful of the three if the reminder is ignored, which reminders are.

## Recommendation
Option 1. The officer channel is the right blast radius for the first scheduled post the app makes,
and the digest already exists, so the only new thing is the schedule and the empty-week case.
Moving to option 2 later is a channel id change once the format has been seen a few times.

Option 2 is the one to think about rather than drift into: parse rankings posted guild-wide are a
social decision, not a feature flag, and the pre-raid reminder pings already established that the
app can post without that being a precedent for posting performance data.

## Decided
