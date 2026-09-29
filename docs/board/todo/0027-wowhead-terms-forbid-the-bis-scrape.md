---
not_for_the_loop: a decision on Wowhead's terms of use, which only Rob can take
---
# Wowhead's terms forbid the healer BiS scrape card 0025 was going to build

## What I need from you

**One answer: do we fetch healer BiS lists from Wowhead or not?**

1. **Fetch anyway.** You accept the terms risk. A weekly job reads about 8 Wowhead guide pages.
2. **Not Wowhead.** A new card checks the terms of Icy Veins and Method first, and builds the
   first one that allows it. Healers keep today's empty lists until then.

**Why it needs you.** The risk is yours: the terms are Wowhead's contract with you. Reading more
cannot settle this.

**Pass:** one `**Decided:**` line in Comments, naming 1 or 2.
**Fail:** no answer. Card 0025 criteria #3, #4 and #7 stay open, and healers see no BiS items.

---

**How this came up.** Card 0025 said to read Wowhead's `robots.txt` and terms before building the
scraper, and to stop if either forbids it. On 2026-09-29:

- `robots.txt` does not block `/guides/` for a normal user agent. It does block `ClaudeBot`,
  `anthropic-ai`, `GPTBot` and other AI crawlers from the whole site.
- The terms (`wowhead.com/tos` sends you to `corp.fanbyte.com/legal/terms`) say you must not
  "access or search the Service or download content from the Service using any engine, software,
  tool, agent, device or mechanism (including spiders, robots, crawlers, data mining tools or the
  like) other than ... generally available third-party web browsers". They also forbid attempts to
  "datamine" the content.

**On 1.** Low volume, but it is still a breach of the terms. The likely outcome is a blocked IP
address. A person saving the pages by hand in a browser does not clearly escape it, because the
datamine clause still applies to what the command pulls out of the saved page.

**On 2.** Card 0025 already built the per-source table and the tabs. A new source only has to
write rows with its own `source` value, so choosing another site loses no work. Card 0025's
criteria #3, #4 and #7 name Wowhead, so they would move to the new source's card.

## Recommendation

2. The terms are plain, and the tab work does not depend on Wowhead.

Paste this to answer:

    **2026-MM-DD** **Decided:** 2. Not Wowhead. Check the terms of Icy Veins and Method, build the first that allows it.

## Links

**Relates to**
- `0025` - the build that stopped at this check. Its migration, service and tabs are done.

## Comments

**2026-09-29** Raised by the unattended build of 0025, at the card's own terms check.
