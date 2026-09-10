---
name: journal-entry
description: Draft a dated dev journal entry under docs/journal/ from the git log since the last tag (or the last entry) and from the notes in docs/journal/_drafts/notes.md, in the voice of the existing entries. Stages the entry and clears the notes. Never commits, never publishes, never writes outside docs/journal/.
argument-hint: "[optional title for the entry]"
allowed-tools: Read, Write, Glob, Grep, Bash(git log:*), Bash(git describe:*), Bash(git tag:*), Bash(git rev-list:*), Bash(git add docs/journal/*), Bash(git status:*), Bash(git diff:*), Bash(date:*), Bash(ls:*)
---

# Draft a dev journal entry

You are drafting the next entry in `docs/journal/`, a public devlog written by a core contributor.
Work through the steps in order. Stop after staging; the user commits.

## Guardrails (read first, apply throughout)

- **Write only under `docs/journal/`.** The only files you may create or modify are the new entry
  and `docs/journal/_drafts/notes.md`. Do not touch `docs/index.md`, the site, or anything else.
  If something outside `docs/journal/` should change (an index line, say), tell the user at the
  end instead of doing it.
- **Never commit, push, publish, build, or deploy.** No `git commit`, `git push`, `sf`, `npm`, or
  anything that sends content anywhere. Staging with `git add` on the two paths above is the
  last action you take.
- **Never read, quote, summarize, or link anything under an `internal` path.** That means any
  file whose path has a directory segment named `internal` (any case), anywhere in the repo, and
  any file `notes.md` points you to under such a path. When a commit's changed-file list includes
  such a path, omit that path from the entry. If the notes mention such a file, use only what the
  notes themselves say and do not open it.
- **Do not invent.** Everything in "Changes" traces to a commit in the range. Everything in
  "Thinking" traces to the notes. Where a commit message is too terse to explain, say what the
  commit did and no more; do not guess at motive.
- **Nothing about employers, customers, or internal channels.** The existing entries follow this
  rule; so does yours. Attribute feedback generically ("a reviewer on the proposal thread").

## 1. Find the range of commits to cover

Determine two candidate starting points and use whichever is newer:

```sh
git describe --tags --abbrev=0                 # most recent tag, if any
ls docs/journal/*.md                            # dated entries are named YYYY-MM-DD-*.md
git log -1 --format=%H -- docs/journal/<newest dated entry>   # commit that added it
```

- If a tag exists and its commit is newer than the commit that added the newest dated entry, the
  range is `<tag>..HEAD`.
- Otherwise the range is `<that commit>..HEAD`.
- If there are no tags and no dated entries, cover the whole history and say so in the entry.
- If the range is empty, stop and tell the user there is nothing new to write about.

Read the commits with their changed files, never their contents:

```sh
git log --reverse --format='%h %as %s%n%b' --name-only <range>
```

Drop any changed-file path that contains an `internal` segment before you use the list.

## 2. Read the notes

Read `docs/journal/_drafts/notes.md`. It may be empty or missing; both are fine. The notes are the
user's own thinking, jotted between entries. Treat them as the source for the "Thinking" section
and for nothing else. If the notes reference a file, you may read it only if its path has no
`internal` segment and it is inside the repository.

## 3. Match the voice

Read the newest one or two dated entries in `docs/journal/` (not the undated tracking files, and
not anything under `_drafts/`). Match them: first person, plain, specific, no hype, one idea per
sentence. Frontmatter is `title`, `description`, and `date: YYYY-MM-DD`. The body opens with an
`# Title` heading. Aim for 400 to 900 words, shorter when there is less to say.

## 4. Draft the entry

File name: `docs/journal/YYYY-MM-DD-<slug>.md`, where the date is today (`date +%F`) and the slug
is the title in lowercase with hyphens. If the user passed a title as the argument, use it;
otherwise choose a short one that names the period's main change.

Structure:

```markdown
---
title: "<Title>"
description: "<One sentence: what this entry covers.>"
date: YYYY-MM-DD
---

# <Title>

<One or two sentences placing the entry: what period it covers, since which tag or entry.>

## Changes

<Prose derived from the commits, grouped by theme rather than listed chronologically. Name a
file or function only when the reader has to go there. Say what changed and, where the commit
message says so, why. Do not paste commit hashes or the raw log.>

## Thinking

<Prose derived from the notes, rewritten into the entry's voice with the substance preserved.
Open questions stay open. Omit this section entirely when the notes are empty, and say so in
your report to the user.>
```

Write the file. Do not write anything else.

## 5. Clear the notes and stage

Overwrite `docs/journal/_drafts/notes.md` with an empty file, then stage exactly these two paths:

```sh
git add docs/journal/<new entry> docs/journal/_drafts/notes.md
git status --short docs/journal
```

Do not commit.

## 6. Report

Tell the user: the entry's path, the commit range it covers, the word count, whether the notes were
empty, and that `docs/index.md` still needs a line under `### journal/` if they want the entry
listed there. Remind them nothing has been committed.
