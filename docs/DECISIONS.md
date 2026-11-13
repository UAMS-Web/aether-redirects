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

## 2026-11-09: Lookup lists, cascade, parameters and permissions are stored as two collections and a global (#45)

**Decision.** The tagged URL builder's lookup data lives in the site's content as two collections, one of lists and one of options, so the Control Panel edits it as ordinary entries. The cascade is carried by per-step pointers on options: each option can name the downstream list for each later step (source platform, medium, creative format, specifications, placement, and term and content where they apply), the deepest option row that names a list wins, shallower rows are fallbacks, and a step with no pointer at any depth falls back to one generic list per step (seven). A list can include other lists by member order, so a composed source list is its member lists in sequence with entries already present skipped. The parameters a tagged URL carries are defined in a `tagged_url_parameters` global: checkboxes for the Google Analytics standard parameters and a grid for custom ones. All 18 names in use are kept as they are (8 Google Analytics standard, 10 UAMS-only), so no parameter is renamed. Permissions are the native collection and global permissions only; the add-on adds none, and a list-manager role is defined in the content repository. A custom value is appended to the list value with `_` and the result is lowercased, and the per-request suppress flag blanks every `utm_*` value, which are the rules of the spreadsheet being retired.

**Why.** Two collections keep the Control Panel to two navigation items and eight permission rows however many lists exist, where a taxonomy per list multiplies both by about 265. The data is small (about 3,355 option rows, median two per list, largest 345), so one file per entry is cheap. The cascade shape was read from the formulas of the current spreadsheet, not guessed: its option rows carry pointers to downstream tables, a deeper row wins through an `IFERROR` chain, and a miss lands on a generic table. Pointers on entries reproduce that behavior, change a step's list or fallback by editing a field, and need no rules table. A global with a grid fits parameters because the set is small and edited rarely. Native permissions suffice because the add-on builds no list-editing screens, so a custom permission would gate nothing.

**Rejected.** A taxonomy per list: 265 navigation items and permission rows, and adding a list needs configure-taxonomies rights. One global with a grid for the lists, or an add-on settings file: the whole data set in one file at thousands of rows, and a settings file lives in the host repository rather than the content repository. One tree collection: the cascade is a graph with shared lists (77 targets are shared, one by 211 rows), so a tree would duplicate options. A separate cascade rules collection: more indirection than the data needs, since no rule spans several parents. Cascade rules in code: a Control Panel user could not change a step's list or fallback. An add-on config file for parameters: a change needs a deploy. A new add-on permission: it would gate nothing under native storage.

**Open.** Whether the Control Panel selector can send a sibling field's value, which decides how much custom interface code the cascade needs (`UAMS-Web/aether-redirects#26`). Whether an add-on can ship collection blueprints the way it ships the `redirects` global's. The spreadsheet's "Inherit UTM Tables?" 0/1 column is read by no formula in the workbook and is left out of the design; its meaning is a question for the sheet's owner.

**Companion.** `UAMS-Web/aether-redirects#45`, `UAMS-Web/aether-redirects#26`, `UAMS-Web/uams-statamic#301`.

## 2026-11-11: The add-on serves the parameters blueprint, and the lookup collections' blueprints are reference files (#51)

**Decision.** The add-on serves the `tagged_url_parameters` global set's blueprint as a fallback, the way it serves the `redirects` one, and that global also carries the generic fallback list of each cascade step as `fallback_<step>` fields. It serves no blueprint for the lists and options collections: their fields are the reference files `docs/blueprints/lookup_list.yaml` and `docs/blueprints/lookup_option.yaml`, which the content repository copies into its blueprint directory. The handles are `lookup_lists`, `lookup_options` and `tagged_url_parameters`, named in `TaggedUrlLookup`.

**Why.** Read against `statamic/cms` v6.40.0 (`34dd84d35d9aaaeb86c22d7a4416e833f56c5bf6`, installed copy): `Collection::getEntryBlueprints()` reads `Blueprint::in('collections/<handle>')`, which lists the files of that namespace and consults no fallback, and `BlueprintRepository::setFallback()` serves only `find()`. The one other route, `addNamespace()`, throws `Undefined array key 0` while listing unless the namespace is registered under both `collections.<handle>` and `collections/<handle>`, and once registered it replaces the host's blueprint directory for that collection, so a host's own blueprint is ignored and only a copy under `vendor/` can override it. A global set has the fallback hook, so its blueprint ships the way the `redirects` one does. Putting the generic fallbacks in that global gives the Control Panel one place to choose them without a third instance for the host to define.

**Rejected.** Registering the collection namespaces: it depends on the two spellings and removes the host's precedence, which is the workaround the ticket said not to build. Shipping the collection YAML under `resources/blueprints/collections` unserved: files that look served and are not. A separate global for the fallbacks: one more instance for the host to define for seven fields.

**Companion.** `UAMS-Web/aether-redirects#51`, `UAMS-Web/aether-submodule-content#96`.

## 2026-11-13: Domain requests are `redirect_domains` entries gated by the same workflow, with a host policy and a 12-week buffer (#83)

**Decision.** A request for a redirect domain is saved as a published entry of the `redirect_domains` collection with `status: pending`, `submitted_by`, the validity window, `requester` and `notes`, the fields a `redirects` global row carries, so the engine and the edge manifest withhold it exactly as they do a row. The utility's `host` field selects the store: none means the `redirects` global, as before. The review queue lists both. A submitted domain must be one of the three vanity domains or a domain the collection already serves. The destination-to-domain mapping applies to the three vanity domains only: a `uams.edu` page goes on `uams.info`, a `uamshealth.com` page on `uams.health`, and `uamsbaptist.health` also takes any `uamshealth.com` page. Any other pairing is refused for a creator and saved, still pending, for a user who can approve. Collisions compare the host as well as the path, so the same path on two hosts or on a host and the main site does not collide. On a vanity domain a rule's window is counted as ending 12 weeks after its last day, so a new rule may start exactly 12 weeks after, and a rule with no end date blocks its path without limit.

**Why.** The serving side already read `status`, `valid_from` and `valid_until` from an entry, so storing the workflow fields as entry data needs no change to the engine or the manifest. Keeping the 12-week rule in the detector, and not in the store, applies it to every request and leaves the global path as it was. `go.uams.edu` is not a vanity domain, so it carries no destination mapping and no buffer; its generated codes are `UAMS-Web/aether-redirects#84`.

**Rejected.** Writing domain requests into the global set with a host column: the engine and manifest read the collection, so the rule would not serve. Extending the buffer to every host: it would put a 12-week hold on `go.uams.edu` codes, which `#84` and `#15` decided are never reissued instead. Letting a creator save a mismatched pairing as pending: it leaves the check to whoever reviews, where the policy asks for an approver to make the exception.

**Open.** The host's `redirect_domain` blueprint does not show the workflow fields until the host copies them from `docs/blueprints/redirect_domain_workflow.yaml`, since an add-on cannot serve a collection blueprint (`UAMS-Web/aether-redirects#35`). Entries made by the utility store them regardless.

**Companion.** `UAMS-Web/uams-statamic#191` (imports the domains' rules into the collection), `UAMS-Web/aether-redirects#84`.

## 2026-11-13: A redirect rule's host refers to a record of a domain list (#24)

**Decision.** Following `UAMS-Web/uams-statamic#325` (option B), a `redirect_domains` entry's `host` holds the id of a published record of the `redirect_hosts` collection, not a domain typed as text. The trusted-host list (`RedirectDomainRules::publishedHosts()`, read by the middleware that answers a redirect domain and by the host's trusted-host list) is the hosts of those records, so publishing a rule no longer adds a trusted address and a new domain needs no deploy. A save listener enforces it: a rule is refused unless its host is a published record, and a record is saved lowercased and without a trailing dot, refused if it is not a valid domain or another record already names it. Creating or changing a record needs the `manage redirect domains` permission from a signed-in user (a super user holds it); a save with no signed-in user, such as the migration, is not blocked. The readers resolve a rule's host through the records, and a host string that has not been migrated still resolves while a record names that domain, so migrating changes nothing served. `php artisan redirects:migrate-domain-hosts` creates one record per distinct valid host and rewrites each entry to its record's id; it is idempotent, reports an invalid host and leaves it, and takes `--dry-run`. The request utility offers the records' hosts, and a vanity domain is offered only once it has a record. Domain settings such as the whole-domain toggle are left on the rule.

**Why.** The `host` field is the input to the trusted-host list, and a record per address makes a trusted address something a person with the narrower permission adds on purpose. Resolving unmigrated strings against the records means the migration can run at any time, and a domain nobody listed stops serving rather than being trusted by accident. The guard is a save listener because the add-on cannot ship the collection's blueprint (`UAMS-Web/aether-redirects#35`), so a blueprint rule alone would leave the field unchecked wherever the host has not copied it.

**Rejected.** A taxonomy for the list and fixed blueprint options: decided in `UAMS-Web/uams-statamic#325`. Treating a signed-in-user-less save as unauthorized: it would block the migration and the importer. Trusting an unmigrated string whether or not a record names it: it keeps the hole the list closes.

**Open.** The host copies `docs/blueprints/redirect_hosts_collection.yaml`, `redirect_host.yaml` and `redirect_domain_host_field.yaml` into its content, changes the `redirect_domains` collection `title_format` to `{{ host:host }}...`, grants the new permission to whoever maintains the list, and runs the migration. The importer writes the host as a string and must write a record id (`UAMS-Web/wordpress-importer#347`). Deleting a record leaves its rules unserved and reported as invalid hosts by the reader. Moving the whole-domain toggle onto the record is out of scope here.

**Companion.** `UAMS-Web/uams-statamic#325`, `UAMS-Web/wordpress-importer#347`, `UAMS-Web/aether-redirects#83`.
## 2026-11-13: Standard parameters take fixed sources, and the channel list starts the cascade (#26)

**Decision.** Each Google Analytics standard parameter has a fixed source in code: `utm_source` takes the source step (the list the channel choice names), `utm_source_platform`, `utm_medium`, `utm_creative_format`, `utm_term` and `utm_content` take the step of the same name, and `utm_campaign`, `utm_marketing_tactic` and `utm_id` take the request field of the same name. The `standard_parameters` checkboxes on the `tagged_url_parameters` global only choose which of them a URL carries; standard parameters come first in the order Google lists them, then custom ones by their `order`. The channel step works like every other step: the global gains `channel_list`, naming the list of channels a request starts from, and `fallback_source`, and options gain a `source` pointer, so `source` is the first entry of `TaggedUrlLookup::STEPS`.

**Why.** Decided by Josh on 2026-11-13, from worked examples; `utm_id` was left open there and takes a request field, the default that ruling allowed. The step names already match the standard names, so a fixed map needs no blueprint change for the standard parameters and cannot be misconfigured. Giving the channel a list field and the source step a pointer and a fallback lets one cascade rule (deepest choice, then the generic list) cover every step.

**Rejected.** A "value comes from" mapping per standard parameter on the global: more fields for a mapping no site needs to change. Treating the channel as a request field whose options are one named list: the source step would then be the only step without a pointer and a fallback.

**Companion.** `UAMS-Web/aether-redirects#26`, `UAMS-Web/aether-redirects#45`.
