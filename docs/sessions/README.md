# Session logs

Durable narrative for substantive Claude Code sessions in this repo.

## What goes here, what doesn't

The repo is the source of truth for **code**. Git log is the source of
truth for **what changed and why** (commit messages). This folder is
the source of truth for **what we learned that won't survive in either
of those** — the irreducible insights from a session.

**Worth a log entry:**
- Root-cause narratives that won't fit in a commit message
- Validation datapoints measured live (accuracy numbers, before/after)
- Operational findings that contradict configured values
- Negative results — things tried that didn't work
- Decisions with abandoned alternatives — *X over Y because Z*

**Not worth a log entry:**
- Routine fixes where the commit message is the explanation
- Exploration that didn't reach a conclusion
- Anything already in `git log`, ARCHITECTURE.md, GLOSSARY.md, or memory
- Transcribed chat — the chat is the chat; log derived value, not narration

## Discipline

- One file per substantive session: `YYYY-MM-DD_short-topic.md`
- Append findings as you go, not at the end
- Aim for **≤5 entries per session**. If you're writing more, the value
  belongs in commits or `docs/` instead
- Most sessions need no log at all — that's correct

## When to start a log

Mid-session, ask once: *"Is there anything we've learned that won't
survive in code, commits, or memory?"* If yes, three lines and move
on. If no, nothing.

## Existing logs

- [2026-04-12_eldt-debugging-recovery.md](2026-04-12_eldt-debugging-recovery.md)
  — recovered from a chat that died from 4K-screenshot dimension overflow.
  Includes root-cause narratives for v0.5.10–v0.5.12 fixes, ELDT accuracy
  datapoints, OOOI table, operational findings.
