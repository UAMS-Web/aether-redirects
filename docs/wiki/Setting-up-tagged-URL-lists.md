Written against v0.4.0, with the cascade endpoint of `main` after it (marked below).

A tagged URL is a destination plus the campaign parameters a report reads. This page sets up the lists a requester chooses them from, and the parameters a tagged URL carries, so that editors add, change and retire entries in the Control Panel and no code changes. The add-on ships the mechanism and no data: its repository is public and the lists are the site's own content.

It assumes the add-on is installed (see [Installation (README)](https://github.com/UAMS-Web/aether-redirects#installation)).

## What the site creates

| What | Handle | Where it comes from |
| --- | --- | --- |
| A collection of lookup lists | `lookup_lists` | Created by the site; fields from [`lookup_list.yaml`](https://github.com/UAMS-Web/aether-redirects/blob/main/docs/blueprints/lookup_list.yaml) |
| A collection of lookup options | `lookup_options` | Created by the site; fields from [`lookup_option.yaml`](https://github.com/UAMS-Web/aether-redirects/blob/main/docs/blueprints/lookup_option.yaml) |
| A global set of parameters | `tagged_url_parameters` | Created by the site; the add-on serves its blueprint |
| A collection of tagged-only requests | `tagged_url_requests` | Created by the site; fields from [`tagged_url_request.yaml`](https://github.com/UAMS-Web/aether-redirects/blob/main/docs/blueprints/tagged_url_request.yaml) |

The handles are fixed: the add-on reads the data by them (`TaggedUrlLookup` and `TaggedUrlRequestStore` name them).

## 1. Create the two lookup collections

1. In the Control Panel, create the collections `lookup_lists` and `lookup_options`.
2. Copy the two reference blueprints into the site, each under its collection's folder:

   ```text
   resources/blueprints/collections/lookup_lists/lookup_list.yaml
   resources/blueprints/collections/lookup_options/lookup_option.yaml
   ```

   The add-on serves no blueprint for these two collections. Statamic reads a collection's entry blueprints from files only, so a site without the copies gets Statamic's default fields and the lists have no `members`, `value` or pointers.

A **list** has a title and, optionally, member lists. An **option** belongs to one list and has:

| Field | Effect |
| --- | --- |
| Label (`title`) | The text a requester sees. |
| Value (`value`) | The text put in the tagged URL when the option is chosen. |
| List (`list`) | The one list the option belongs to. |
| A downstream list for each step | The list the next steps offer once this option is chosen: `source`, `source_platform`, `medium`, `creative_format`, `specifications`, `placement`, `term` and `content`. |
| Active (`active`) | Off retires the option. It is no longer offered, and a URL already issued with it is unchanged. |
| Order (`order`) | Position within its list, lowest first. Ties keep their stored order. |

Retire an option instead of deleting it. A request that was saved with a retired option still takes the option's value when it is approved.

## 2. Create the parameters global

Create the global set `tagged_url_parameters`. The add-on serves its blueprint, so nothing is copied, and a site that keeps its own blueprint under that handle wins. The values are read from the default site.

| Field | Effect |
| --- | --- |
| `channel_list` | The list of marketing channels a request starts from. |
| `fallback_<step>` | The generic list of a step, used when no choice made so far names a list for it. One field for each of the eight steps. |
| `standard_parameters` | Which of the Google Analytics standard parameters a URL carries. |
| `custom_parameters` | The site's own parameters, beside the standard ones. |

### The cascade

A requester starts with a channel. Each later step offers one list, chosen like this:

1. The deepest choice made so far that names a list for the step wins. A choice names a list through its downstream list field for that step.
2. A shallower choice that names a list is the fallback when no deeper choice does.
3. A step no choice names offers its generic list (`fallback_<step>`).
4. A step with no generic list either offers nothing.

The steps are asked in this order: `source`, `source_platform`, `medium`, `creative_format`, `specifications`, `placement`, `term`, `content`. The channel option names the source list through its `source` field, so the source step works like every later one.

### Member lists

A list can include other lists. A composed list offers its own options first and then each member list's options in member order. An option whose value is already present is skipped, so a composed list shows no repeats. A member that leads back to a list already being read is not read again.

### Standard parameters

The Google Analytics campaign parameters ([URL builders: Collect campaign data with custom URLs](https://support.google.com/analytics/answer/10917952)) are built in, and their sources are fixed in code. The checkboxes only choose which of them a URL carries. Google asks for `utm_source`, `utm_medium` and `utm_campaign` on every tagged URL.

| Parameter | Value comes from |
| --- | --- |
| `utm_source` | The source step. |
| `utm_source_platform`, `utm_medium`, `utm_creative_format`, `utm_term`, `utm_content` | The step of the same name. |
| `utm_campaign`, `utm_marketing_tactic`, `utm_id` | The request field of the same name. |

Google notes that `utm_creative_format` and `utm_marketing_tactic` are not currently reported in Google Analytics.

### Custom parameters

Each row of `custom_parameters` is one parameter. It can be added, renamed, reordered, switched off or removed without a code change.

| Field | Effect |
| --- | --- |
| `enabled` | Off leaves the parameter out of every new URL. |
| `name` | The name in the URL: letters, numbers and underscores. A row with any other name is ignored. |
| `order` | Position in the URL, lowest first, after the standard parameters. Rows with the same order keep their row order. |
| `source_type` | Where the value comes from: a request field, a lookup list, or a fixed value. A row with none counts as a lookup list. |
| `request_field`, `list`, `fixed_value` | The source that matches the type. A row with no source for its type is ignored. |

## Parameters the system fills on a vanity request

Two request field handles are filled by the add-on when a vanity URL is requested with tagged URL choices, and the requester is never asked for them. A request that types one is refused.

| Handle | Value |
| --- | --- |
| `vanity_url` | The vanity domain and back-half of the redirect, lowercased and percent-encoded, such as `uams.info%2Fflu`. Blank on a main-site request and when the request leaves the `utm_*` parameters off. |
| `qr_code` | `false` on the vanity redirect and `true` on its `-QR` redirect. Blank on a main-site request. |

Add a parameter whose `source_type` is `request_field` and whose `request_field` is one of these handles, such as `utm_vanity` and `utm_qr`, to put the values on the URL. The vanity redirect and its `-QR` redirect are each issued their own URL when an approver approves the request, and a later change to a list or a parameter leaves both as issued.

## How the URL is built

- Parameters are added in order, standard first and then custom, each name and value percent-encoded, so a value with a space or an `&` still yields a valid URL.
- A parameter with no value is left out.
- A parameter the destination already carries is replaced, not repeated, and the destination's fragment stays at the end.
- A free-text custom value typed beside a list choice is appended to the list's value with `_`, and the joined result is lowercased. With no custom value the list's value is used as it stands.
- A request's suppress flag leaves every `utm_*` parameter out and keeps the others.

## A worked example

The lists:

| List | Option (value) | Names |
| --- | --- | --- |
| Channels | Social (`social`) | `social-sources` as its source list |
| Social sources | Facebook (`Facebook`) | `facebook-media` as its medium list |
| Facebook media | Paid social (`paid_social`) | |
| Generic media (the `medium` generic list) | Other (`other`) | |

`standard_parameters` holds `utm_source`, `utm_medium` and `utm_campaign`. A request for `https://www.uams.edu/clinics/flu` chooses Social, then Facebook with the custom value `Spring Push`, then Paid social, and gives `utm_campaign` as `flu-2026`. The source step offered Social sources because the channel named it. The medium step offered Facebook media because Facebook, the deepest choice, named it; had Facebook named none, the generic list would have been offered. The URL is:

```text
https://www.uams.edu/clinics/flu?utm_source=facebook_spring%20push&utm_medium=paid_social&utm_campaign=flu-2026
```

The unit test `tests/Unit/Support/TaggedUrlWikiExampleTest.php` builds this example and fails if the page and the code disagree.

## Who can edit what

The lists, the options and the parameters are ordinary Statamic collections and a global set, so the native permissions apply: whoever may edit those entries and that global may add, change and retire them. The add-on adds no permission for the lists. Defining a list-manager role is the site's content repository's job.

## Tagged-only requests today

A request for a tagged URL alone is saved as an entry of `tagged_url_requests` and issued when an approver approves it. The issued URL is stored on the request, so a later change to a list or a parameter does not change a URL already issued. Today the request carries the destination, `utm_campaign`, `utm_id`, `utm_marketing_tactic` and the suppress flag, so a site whose standard parameters read steps gets those parameters left out until the cascade choices are saved.

## Reading the cascade from a page (unreleased)

Since the release after v0.4.0, a signed-in user who may request a tagged URL can read the cascade as JSON, which the request form uses to narrow each list as choices are made:

```text
GET <cp>/redirects/marketing/tagged-url/cascade?channel=<option id>&steps[source]=<option id>
```

The route is named `marketing-redirects.tagged-url.cascade`. The query takes `channel`, `steps[<step>]` and `lists[<list id>]`, each an option id. The answer holds `channel` (the channel options), `steps` (each step an enabled parameter reads, with its options), `lists` (each list-sourced custom parameter, its list and options) and `fields` (the request field handles the parameters read). An option is its `id` and `label`. A step no enabled parameter reads is left out, an id that no longer exists is ignored, and a site with no lookup data gets an empty answer with a `200`.

## Not available yet

These parts of the tagged URL builder are tracked on [UAMS-Web/aether-redirects#26](https://github.com/UAMS-Web/aether-redirects/issues/26) and are not in a release:

- **Saving a request's cascade choices** and issuing the URL from them at approval, tracked in [#119](https://github.com/UAMS-Web/aether-redirects/issues/119).
- **The cascade fields on the request form** and the choices on the review screen, tracked in [#120](https://github.com/UAMS-Web/aether-redirects/issues/120).
- **Whether a vanity URL request also carries a tagged URL** is an open scope question on #26, so nothing here describes it.
- **Which request fields a custom parameter may read** is also open. Until it is decided, the fields a request saves are the three above.

Until the choices are saved, the lists and parameters set up here take effect for the cascade endpoint and for any parameter whose value comes from a request field or a fixed value.
