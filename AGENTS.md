# AGENTS

This file provides guidance for LLM coding agents working on this repository.

## Project Purpose

Kaliop Image Bundle extends Ibexa image handling with generated WebP variations,
density multiplier variations, focal-point-aware crops, Fastly Image Optimizer
support, Admin UI image variation filtering, and a Twig `srcset` helper.

## Architecture

- `src/bundle`: Symfony bundle integration, DI configuration, compiler passes, service wiring, and Twig integration
- `src/lib`: implementation details such as variation handlers, configuration providers, image filters, listeners, and UI providers

When changing behavior, preserve this layered split. Bundle integration may
depend on library code, but library code must not depend on bundle classes.

## Supported Stack (1.x line)

Do not widen platform constraints for this major line without explicit
maintainer approval.

### 2.x

- Ibexa 5.0
- Symfony 7.4 LTS
- PHP 8.3+

### 1.x

- Ibexa 4.6
- Symfony 5.4 LTS
- PHP 8.1+

## Branching and Versioning

- `main` is the next feature release branch
- previous major lines live on dedicated maintenance branches (for example `1.x`)
- SemVer is required
- no backward-incompatible changes in a minor/patch release

## Coding Rules

- Follow existing code style and strict types
- Keep dependencies minimal
- Prefer adding new behavior in `src/lib` and wiring it in `src/bundle`
- Avoid introducing framework or Ibexa version coupling outside the supported line
- Keep generated variation naming backward compatible

## Required Checks

Before finalizing changes, run:

```bash
composer validate --strict
composer check-cs
composer phpstan
composer deptrac
```

## Documentation Rules

If behavior changes:

- update `README.md`
- update release notes in GitHub Releases
- add upgrade notes for breaking changes

## Release Workflow Notes

- Releases are tag-driven (`vX.Y.Z`) via GitHub Actions
- Packagist is notified from the release workflow using `PACKAGIST_TOKEN`
