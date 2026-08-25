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

## Local Checks

When testing this repository before Packagist registration, register a temporary local Core repository in your Composer root instead of committing a path repository:

```bash
composer validate --strict
composer config repositories.seviaflow-core '{"type":"path","url":"../core","options":{"symlink":true,"versions":{"seviaflow/core":"0.1.x-dev"}}}'
composer update
composer check
```
