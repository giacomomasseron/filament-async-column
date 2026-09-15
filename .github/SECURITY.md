# Security Policy

## Supported Versions

Only the latest 1.x release receives security fixes. If you can, please confirm the issue
against it before reporting.

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |
| < 1.0   | :x:                |

## Reporting a Vulnerability

**Please don't report security vulnerabilities through public GitHub issues, discussions or pull
requests.**

Report them privately through GitHub's
[private vulnerability reporting](https://github.com/giacomomasseron/filament-async-column/security/advisories/new).
If you can't use that, email giacomom@gmail.com instead.

Please include:

- the package version, plus your Filament, Laravel and PHP versions;
- what an attacker can do and what they need to do it;
- steps to reproduce - ideally a minimal table definition and the request or cell token that
  triggers the issue.

## What to Expect

- An acknowledgement within 7 days.
- An assessment of the report, and a timeline for a fix if it's confirmed.
- A patched release and a published GitHub security advisory crediting you, unless you'd rather
  stay anonymous.

Please give a fix a reasonable chance to ship before disclosing the issue publicly.

## Scope

The package's security model is described in the [Security section of the README](../README.md#security):
a forged cell token must never reach data - or even reveal that a column exists - that the
requesting user could not already see through the table itself. Anything that breaks that
guarantee is in scope.

The following are documented behaviour rather than vulnerabilities:

- **Request rate.** `max_batch_size` bounds how many resolvers a single request can invoke, not
  how often requests are made. Throttling is the application's responsibility.
- **The active search term isn't applied when resolving.** Search narrows what's displayed, not
  what's authorized; tenancy, global scopes and table filters still apply.
- **What your own code reveals.** Output returned by your resolvers, or by an `errorStateUsing()`
  callback that chooses to expose the underlying exception.
