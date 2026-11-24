Written against v0.5.2.

The Redirects add-on serves redirects for a Statamic install: a `redirects` global set answered when a page is not found, rules on redirect domains such as `uams.info`, an edge manifest built from those rules, and a Control Panel workflow in which staff request vanity URLs, short links, QR codes and tagged URLs and an approver releases them.

This wiki holds the long-form documentation. The [README](https://github.com/UAMS-Web/aether-redirects#readme) keeps the requirements, the installation and the notes for a site that used the old package name.

## Getting started

- [Installation (README)](https://github.com/UAMS-Web/aether-redirects#installation): add the package to a Statamic install.
- [Setting up a site](Setting-up-a-site.md): the collections, global sets and blueprints the host site creates, and the order to do it in.
- [Your first vanity URL](Your-first-vanity-URL.md): request a vanity URL, approve it and see it serve.

## Guides

- [Requesting a vanity URL](Requesting-a-vanity-URL.md): domains, back-halves, validity dates, collisions and QR codes.
- [Making a short link](Making-a-short-link.md): a generated code on `go.uams.edu`.
- [Requesting a tagged URL](Requesting-a-tagged-URL.md): a destination with campaign parameters, with or without a vanity URL.
- [Reviewing redirect requests](Reviewing-redirect-requests.md): approving and rejecting.
- [Setting up redirect domains](Setting-up-redirect-domains.md): the domain list and the rules served on a domain.
- [Writing redirect rules](Writing-redirect-rules.md): rules in the `redirects` global set and on a domain.
- [Publishing the edge manifest](Publishing-the-edge-manifest.md): the command a deploy runs.
- [Setting up tagged URL lists](Setting-up-tagged-URL-lists.md): the lookup lists, the cascade and the parameters a tagged URL carries.

## Troubleshooting

- [Troubleshooting](Troubleshooting.md): one entry per symptom, with its cause and fix.

## Reference

- [Configuration](Configuration.md): every key of `config/aether-redirects.php`.
- [Permissions](Permissions.md): who may request, approve and manage.
- [Redirect rules](Redirect-rules.md): rule fields, matching order and the `find_redirect_rule` tag.
- [Commands](Commands.md): the three Artisan commands.
- [Control Panel routes](Control-Panel-routes.md): the screens, navigation items and endpoints.
- [Blueprints and collections](Blueprints-and-collections.md): which blueprint the add-on serves and which the site copies.

## Project

- [Project](Project.md): where the add-on is developed and how to report a problem.
