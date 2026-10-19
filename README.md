# SeviaFlow API

Composer package: `seviaflow/api`

Repository: `https://github.com/seviaflow/api`

## Beta compatibility

The free beta supports Laravel 12 on PHP 8.2+ and Laravel 13 on PHP 8.3+. Laravel 11 is outside this beta's support scope; its security support ended on March 12, 2026. CI covers these framework targets. Package unit tests use SQLite; the Northstar integration and browser suites use MariaDB 10.11. PostgreSQL is not yet a verified beta target.

API is the optional headless REST/OpenAPI adapter over `seviaflow/core`. It owns API routes, resource serialization, CRUD responders, workflow endpoints and OpenAPI generation. It requires Core, not Copilot or a licensing adapter.

API does not require Livewire or `seviaflow/ui`. It is part of the free edition and does not require paid API entitlement at runtime. Authentication, authorization and throttling still apply.

## Installation Status

SeviaFlow is pre-release. This package is licensed under the [MIT License](LICENSE), which permits commercial use, modification and redistribution with its copyright and license notice. Release tags, distribution endpoints and support scope are documented separately.

```bash
composer require seviaflow/api:^0.1@dev
```

## Upgrade from the former paid profile

Remove `SEVIAFLOW_API_LICENSE_ENFORCE` and the obsolete `licensing` block from published API configuration. The legacy `seviaflow.api.license` middleware alias remains as a deprecated pass-through, so old configurations do not break. Do not remove host authentication, permissions or throttling. Only remove `seviaflow/licensing` if no other installed module (such as paid Copilot) needs it. Clear the host configuration and route caches after updating.

No stable artifacts are published by this change. The API package uses MIT; repository visibility and published artifacts remain separate release decisions.

## Routes

Default API prefix: `api/admin`

Default route names: `seviaflow.api.*`

## Authentication and Abilities

The package default uses the host-configured `auth:sanctum` middleware, but API
hosts may replace it with another real Laravel guard. SeviaFlow never creates a
token or treats an ability as authentication; the configured guard remains
responsible for bearer validation and revocation. The playground uses
`['api', 'auth:web']` for its session-backed local host.

When the authenticated user exposes `tokenCan()` and an active access token, API
authorization requires both the user/Resource policy and a token ability. Read
operations accept `resources.read`, `resources.{resource}.read`, or the matching
resource/ability aliases. Create, update, delete and workflow operations require
the corresponding write ability (`resources.write` or
`resources.{resource}.write`, with documented aliases). A read-only token cannot
perform a mutation, and a revoked token must be rejected by the host guard before
the responder runs.

Resource `enabled`, readable, creatable, updatable, deletable and workflow
capabilities are applied consistently to routes and OpenAPI discovery. Empty
write field lists do not expose POST/PATCH operations.

## Local Checks

When testing this repository before Packagist registration, register a temporary local Core repository in your Composer root instead of committing a path repository:

```bash
composer validate --strict
composer config repositories.seviaflow-core '{"type":"path","url":"../core","options":{"symlink":true,"versions":{"seviaflow/core":"0.1.x-dev"}}}'
composer update
composer check
```
