# Decision log

The canonical record of **why** this repository is the way it is: the reasoning and the **rejected alternatives** behind decisions that have closed. It is the guard against silently relitigating a settled decision.

**Consult it before acting.** Before proposing a change, reopening a question, or picking an approach, check here first: the path under consideration may already be a decided-and-recorded one, or an explicitly rejected one. If it is, follow the record (or make the case to revisit it); do not quietly re-decide.

**When a seam closes, record the verdict in both places.** Capture the *what*, *why* and rejected alternatives **here** (the cross-ticket index) **and** in the GitHub issue's `## Decision` section (the per-ticket record), keeping the two in sync. This file does not replace the issue tracker, which stays the system of record for a single ticket's status.

See the [`design-decision-forks`](../.claude/rules/design-decision-forks.md) rule for how a fork is surfaced and driven to resolution before its verdict lands here.

## How to add an entry

Oldest first, one entry per closed decision, so **a new entry is appended to the end of the file**. Keep it terse; the issue carries the depth. Each entry is preceded by exactly one blank line, which `scripts/check-decision-log-order.mjs` enforces along with the date order. The file is marked `merge=union` in `.gitattributes`, so two branches that each append an entry merge without a conflict.

```markdown
## YYYY-MM-DD: <short decision title> (<#issue or area>)

**Decision.** <what was decided, in a sentence or two>

**Why.** <the reasoning that settled it>

**Rejected.** <the alternatives considered, and why each lost>

**Companion.** <link to the GitHub issue holding the full reasoning>
```

---

## 2026-10-14: Started from the add-on template (repository setup)

**Decision.** This repository started from `UAMS-Web/statamic-addon-template`. It carries no synced files (`.agents/`, `.claude/`, `.cursor/`) and no `LICENSE`, and `composer.json` has no `license` field.

**Why.** The shared rules and skills reach a repository by onboarding it to the sync in `UAMS-Web/uams-claude-skills`, not by copying a snapshot that goes stale. The license waits on the general decision from UAMS legal in `UAMS-Web/uams-statamic#3039`.

**Rejected.** Snapshotting the synced files into the template: every new add-on would begin with a stale copy that conflicts with its first sync pull request. Copying a license from an existing add-on: legal has not decided which license applies.

**Companion.** `UAMS-Web/uams-statamic#3039`.

## 2026-10-28: The add-on goes to Packagist on MIT, ahead of UAMS legal's decision (repository setup)

**Decision.** The operator decided on 2026-10-28 to publish `uams-web/statamic-redirects` on Packagist, the same way as `UAMS-Web/statamic-form-settings`, so that `UAMS-Web/uams-statamic` can require it by name. The add-on is MIT licensed (`LICENSE`, `"license": "MIT"`), matching the other add-ons that site requires, ahead of `UAMS-Web/uams-statamic#3039`. The Marketplace is not part of this decision.

**Why.** A public Packagist package needs a declared license, and `uams-statamic` requires the add-on by Composer name rather than by path. Matching the sibling add-ons keeps one license across them, and the file can change if UAMS legal decides otherwise.

**Rejected.** Waiting for UAMS legal before declaring a license: the package cannot be listed without one, and the site cannot require it by name until it is listed. Requiring the add-on from a path repository: it ties the site to one machine's checkout.

**Companion.** `UAMS-Web/uams-statamic#397`, `UAMS-Web/uams-statamic#3039`.

## 2026-11-09: Lookup lists, cascade, parameters and permissions are stored as two collections and a global (#45)

**Decision.** The tagged URL builder's lookup data lives in the site's content as two collections, one of lists and one of options, so the Control Panel edits it as ordinary entries. The cascade is carried by per-step pointers on options: each option can name the downstream list for each later step (source platform, medium, creative format, specifications, placement, and term and content where they apply), the deepest option row that names a list wins, shallower rows are fallbacks, and a step with no pointer at any depth falls back to one generic list per step (seven). A list can include other lists by member order, so a composed source list is its member lists in sequence with entries already present skipped. The parameters a tagged URL carries are defined in a `tagged_url_parameters` global: checkboxes for the Google Analytics standard parameters and a grid for custom ones. All 18 names in use are kept as they are (8 Google Analytics standard, 10 UAMS-only), so no parameter is renamed. Permissions are the native collection and global permissions only; the add-on adds none, and a list-manager role is defined in the content repository. A custom value is appended to the list value with `_` and the result is lowercased, and the per-request suppress flag blanks every `utm_*` value, which are the rules of the spreadsheet being retired.

**Why.** Two collections keep the Control Panel to two navigation items and eight permission rows however many lists exist, where a taxonomy per list multiplies both by about 265. The data is small (about 3,355 option rows, median two per list, largest 345), so one file per entry is cheap. The cascade shape was read from the formulas of the current spreadsheet, not guessed: its option rows carry pointers to downstream tables, a deeper row wins through an `IFERROR` chain, and a miss lands on a generic table. Pointers on entries reproduce that behavior, change a step's list or fallback by editing a field, and need no rules table. A global with a grid fits parameters because the set is small and edited rarely. Native permissions suffice because the add-on builds no list-editing screens, so a custom permission would gate nothing.

**Rejected.** A taxonomy per list: 265 navigation items and permission rows, and adding a list needs configure-taxonomies rights. One global with a grid for the lists, or an add-on settings file: the whole data set in one file at thousands of rows, and a settings file lives in the host repository rather than the content repository. One tree collection: the cascade is a graph with shared lists (77 targets are shared, one by 211 rows), so a tree would duplicate options. A separate cascade rules collection: more indirection than the data needs, since no rule spans several parents. Cascade rules in code: a Control Panel user could not change a step's list or fallback. An add-on config file for parameters: a change needs a deploy. A new add-on permission: it would gate nothing under native storage.

**Open.** Whether the Control Panel selector can send a sibling field's value, which decides how much custom interface code the cascade needs (`UAMS-Web/aether-redirects#26`). Whether an add-on can ship collection blueprints the way it ships the `redirects` global's. The spreadsheet's "Inherit UTM Tables?" 0/1 column is read by no formula in the workbook and is left out of the design; its meaning is a question for the sheet's owner.

**Companion.** `UAMS-Web/aether-redirects#45`, `UAMS-Web/aether-redirects#26`, `UAMS-Web/uams-statamic#301`.
