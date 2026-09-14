# SeviaFlow API

Composer package: `seviaflow/api`

Repository: `https://github.com/seviaflow/api`

API is the optional headless REST/OpenAPI adapter over `seviaflow/core`. It owns API routes, resource serialization, CRUD responders, workflow endpoints and OpenAPI generation.

API depends on Core only. It does not require Livewire or `seviaflow/ui`.

## Installation Status

SeviaFlow is pre-release. The `main` branch is aliased as `0.1.x-dev`; the first stable tag is expected to start at `0.1.0`.

```bash
composer require seviaflow/api:^0.1@dev
```

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
