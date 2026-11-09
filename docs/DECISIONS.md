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

## 2026-11-09: The marketing-redirect workflow moves to the add-on; the host keeps the `redirect_domain` blueprint (`UAMS-Web/uams-statamic#398`)

**Decision.** The operator decided on 2026-11-09 that the marketing-redirect request and approval workflow moves out of `UAMS-Web/uams-statamic` into this add-on: the CP controller, `MarketingRedirectStore`, `RedirectCollisionDetector`, the two Vue pages, the two permissions, the CP routes under `redirects/marketing` and the two Tools nav items. The host keeps the `redirects` global set the workflow writes into, the `redirect_domains` collection, and that collection's `redirect_domain` blueprint.

**Why.** The workflow writes only to the `redirects` global set the add-on already answers 404s from, so it belongs with the rest of the redirect surface, and keeping it in the site left the extraction half done. The blueprint stays because `Blueprint::addNamespace` cannot serve a collection blueprint on Statamic 6.38.0 (`UAMS-Web/aether-redirects#35`).

**Rejected.** Leaving the workflow in the site and recording it as host-owned: it splits one redirect surface across two repositories with no boundary reason. Moving the `redirect_domain` blueprint too: Statamic cannot load it from an add-on namespace.

**Companion.** `UAMS-Web/uams-statamic#398`.
