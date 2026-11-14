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

## 2026-11-13: A tagged-only request is an entry of its own collection, and approval composes and keeps its URL (#18)

**Decision.** A request for a tagged URL with no vanity URL is made on the same create screen (a "Tagged URL only" choice, sent as `kind: tagged`) and reviewed in the same queue, under the same no-self-approval rule. It is saved pending as an entry of the host's `tagged_url_requests` collection, never as a `redirects` row or a `redirect_domains` entry. Until the cascade choices of `UAMS-Web/aether-redirects#26` reach the form, its tagged-URL part is the destination, the request fields `utm_campaign`, `utm_id` and `utm_marketing_tactic`, and the suppress flag. Approving it composes the URL with `TaggedUrlComposer` from the host's `tagged_url_parameters` global and stores it on the entry as `tagged_url`, so a later change to a parameter or a list does not change an issued URL. The vanity fields (`host`, `source`, `response`, `valid_from`, `valid_until`) are refused on a tagged-only request rather than ignored. The host copies `docs/blueprints/tagged_url_requests_collection.yaml` and `docs/blueprints/tagged_url_request.yaml`; without the collection the screen does not offer the choice and the endpoint refuses it with a message.

**Why.** Decided by Brent on 2026-11-13. A row with no source in a redirect store would make every serving path, the edge manifest and the collision check responsible for skipping it, and a path that forgot would serve a wrong redirect; a collection nothing serves from cannot. A collection also gives the stored, approved record of every tagged URL that `UAMS-Web/uams-statamic#301` decision 4 asks for, in one place for the reporting residual. Composing at approval uses the assembly `#26` already shipped, and the cascade choices add fields to the same request without changing its shape.

**Rejected.** A `kind: tagged` row with no source in the `redirects` global: no host setup, but it relies on every serving and collision path to exclude it. The destination alone as the issued URL until `#26`: it issues no tag. A tagged URL typed by hand: it is the hand assembly `#301` decision 2 retires.

**Companion.** `UAMS-Web/aether-redirects#18`, `UAMS-Web/aether-redirects#26`, `UAMS-Web/aether-redirects#11`, `UAMS-Web/uams-statamic#301`.

## 2026-11-13: Every request answers whether it involves Creative Services, and a yes carries a job number (#25)

**Decision.** Every redirect request answers a yes/no "involves Creative Services" question. A vanity URL request must answer yes and give a job number; an answer of no is refused with a pointer to a `go.uams.edu` short code. A short link or a main-site request may answer either way, and a yes needs a job number. The job number format is one setting, `creative_services.job_number.pattern` with its words in `creative_services.job_number.description`, and by default it checks only that a number is given. The approver sees the answer and the number before approving.

**Why.** The number is what ties a vanity URL to its Creative Services job. Checking only that a number is given keeps the add-on usable before the format is settled, and the approver confirms every number either way. One check class serves every request form, so the tagged-only request (#18) reuses the question, the field and the setting instead of validating a second time.

**Rejected.** A separate format setting per request form: the tagged-only form would drift from the vanity one. Requiring a job number on every request: a request that does not involve Creative Services has none. Defaulting the answer to no: a default makes the answer unintentional, so the form has none and the server requires it.

**Companion.** `UAMS-Web/aether-redirects#25`.

## 2026-11-13: A typed back-half passes four rules, at submission and again at approval (#16)

**Decision.** A back-half the requester types on a vanity domain must be letters, numbers and hyphens, start and end with a letter or number, and have at most 50 characters; must not end in `-QR` in any case; must not be on the `back_half.reserved` setting, which ships empty; and must not be held on that domain, in any case, by a pending request or by an approved redirect that is live, not yet started, or inside the 12-week reuse buffer after its last day. Each refusal names the rule that failed. The same rules run again at approval against the approved rows, so two pending requests for one back-half cannot both go live.

**Why.** Chosen back-halves share one limited namespace with the generated ones on each short domain, and `-QR` names the QR redirect of a vanity URL. A request that was pending when another for the same back-half was approved is the case the second run catches.

**Rejected.** Counting an expired approved redirect as holding the back-half forever: the existing 12-week reuse buffer for vanity paths already says when a path may be reused, and the rule follows it. Counting pending requests at approval: each of two pending requests would block the other.

**Companion.** `UAMS-Web/aether-redirects#16`; who may type a back-half is `UAMS-Web/aether-redirects#13`.

## 2026-11-14: A tagged-only request stores its choices as ids and typed text, and its request fields apart from the entry's own (#119)

**Decision.** A tagged-only request stores the channel, the option chosen at each step, the options chosen from other lists, the typed custom values and the request field values, as ids and text only. The request fields are the handles the site's enabled parameters read, kept under one `request_fields` map. Each submission is checked server-side: a chosen option must be one the cascade offers at that step given the earlier choices, a list choice must belong to a list a parameter reads, a custom value is bounded text, and a field no parameter reads is refused with a `422` naming it. A row saved before this, with its three fixed fields on the entry, reads the same.

**Why.** The issued URL is the record of what was issued, so a later change to a list or a parameter cannot change it, and the choices need no copy of the lookup entries. A handle a site's parameter reads is the site's to choose; stored beside the entry's own fields, a handle named `status` would let a requester set a workflow field, so the values sit in their own map.

**Rejected.** Storing each request field as a field of the entry, as the three fixed ones were: a site-defined handle could collide with a workflow field. Dropping a field no parameter reads: a submission that meant to set it would be saved without it and nobody told. Copying the option entries into the request: it would let a retired option's text outlive the list that retired it, for a record the issued URL already is.

**Companion.** `UAMS-Web/aether-redirects#119`, part of `UAMS-Web/aether-redirects#26`.

## 2026-11-14: A vanity request carries the tagged URL cascade, and the system fills the vanity and QR values, issuing one URL per redirect (#125)

**Decision.** A vanity request on either store (the `redirects` global and `redirect_domains`) is submitted with the same cascade choices as a tagged-only request, checked against the typed destination by the same validator, and kept in a `tagged_choices` field until approval. Approval composes the tagged URL from the destination as typed and the choices, stores it as the redirect's destination, and does not compose it again, so a later change to a list or a parameter leaves it as issued. Two request field handles are the system's to fill and are refused when typed: `vanity_url`, the vanity domain and back-half lowercased and percent-encoded (`uams.info%2Fwalk-qr`), blank for a main-site request or when the request leaves the `utm_*` parameters off; and `qr_code`, `false` on the vanity redirect and `true` on its `-QR` redirect. A request with a QR code is issued two URLs, one for each redirect. A vanity request made with no choices saves and approves as it did.

**Why.** The spreadsheet the tagged URLs were built in carries the vanity URL and a QR flag as columns, so the two values belong to the redirect, not to what the requester types, and the QR redirect's own back-half is what its scans should be counted under. Composing at approval, from stored choices, lets a pair carry two different URLs and keeps an issued URL a record rather than a view of current lists.

**Rejected.** Composing on every serve or save: an issued URL would change with the lists. Letting the requester type the vanity value: it could disagree with the redirect it names. One URL for the pair with the QR flag repointed from the vanity side: the QR redirect could not carry its own back-half.

**Residue.** The sync that repoints a `-QR` redirect from its vanity redirect on every save skips a pair requested with tagged choices, so a later Control Panel edit to a tagged pair's destination does not reach the other side.

**Companion.** `UAMS-Web/aether-redirects#125`, part of `UAMS-Web/aether-redirects#26`; builds on `UAMS-Web/aether-redirects#120`.
