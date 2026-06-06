# Branching Strategy

This document defines the branch strategy used by the ecommerce repositories.

The frontend and backend are separate repositories, but both repositories should use the same branch roles and release flow. The goal is to let staging contain unfinished integration work without accidentally shipping that work to production.

## Branch Roles

### `main`

`main` is the production branch.

Only production-ready changes should enter this branch. Production deployments should come from `main` or from a release tag created from `main`.

### `develop-main`

`develop-main` is the production gate.

This branch contains changes that are candidates for production. Feature branches should be created from `develop-main` so new work starts from the production-ready code line.

### `staging`

`staging` is the staging deployment branch.

This branch represents the code deployed to the staging server. It should receive changes from `develop-staging` through the agreed staging deploy process.

### `develop-staging`

`develop-staging` is the staging gate.

This branch can contain features that are being tested in staging and are not ready for production yet. `develop-staging` must not be merged directly into `develop-main` or `main` unless every change inside it is intentionally ready for production.

### `feature/*`

`feature/*` is the main branch for a feature.

This branch is the source of truth for the feature and the branch that can later be merged into `develop-main` when the feature is production-ready.

Example:

```text
feature/seller-buyer-chat
```

### `feature/*-staging`

`feature/*-staging` is the staging integration branch for a feature.

This branch is used to resolve conflicts against `develop-staging` and prepare the feature for staging without changing the production candidate branch.

Example:

```text
feature/seller-buyer-chat-staging
```

Production must not merge from `feature/*-staging`.

### `hotfix/*`

`hotfix/*` is used for urgent production fixes.

Hotfix branches should start from `main` or `develop-main`, depending on the current release state.

Example:

```text
hotfix/fix-login-error
```

### `hotfix/*-staging`

`hotfix/*-staging` can be used when the same hotfix needs a staging integration branch because `develop-staging` has conflicts or staging-only changes.

If the hotfix can merge cleanly into `develop-staging`, this extra branch is not required.

## Feature Branch Flow

Every feature starts from `develop-main`.

```text
develop-main
  -> feature/name
  -> feature/name-staging
```

`feature/name` remains the source of truth.

`feature/name-staging` is used only for staging integration.

## Staging Flow

Use this flow when a feature needs to be tested in staging:

```text
feature/name
  -> feature/name-staging
  -> merge latest develop-staging into feature/name-staging
  -> resolve staging conflicts in feature/name-staging
  -> feature/name-staging merges into develop-staging
  -> develop-staging deploys or merges into staging
```

Conflict resolution for staging happens in `feature/name-staging`, not in `develop-staging` or `staging`.

## Production Flow

Use this flow when a feature is ready for production:

```text
feature/name
  -> merge latest develop-main into feature/name
  -> resolve production conflicts in feature/name
  -> feature/name merges into develop-main
  -> develop-main merges into main
  -> production deploys from main or a release tag from main
```

Production must use `feature/name`, not `feature/name-staging`.

## Fix Rules During Staging

When a bug is found during staging testing, the default rule is:

```text
Fix in feature/name first.
Then merge feature/name into feature/name-staging.
Then test again in staging.
```

This keeps the production candidate branch and the staging integration branch aligned.

If a fix is made directly in `feature/name-staging` because it is a conflict-specific staging fix, the developer must review whether the fix is also needed in `feature/name`.

## Hotfix Flow

Use this flow for urgent production fixes:

```text
main or develop-main
  -> hotfix/name
  -> develop-main
  -> main
  -> production deploy
```

After production is fixed, bring the hotfix back to staging:

```text
hotfix/name
  -> develop-staging
  -> staging
```

If `develop-staging` has conflicts, use a staging integration branch:

```text
hotfix/name
  -> hotfix/name-staging
  -> develop-staging
  -> staging
```

## Conflict Rules

Conflict resolution must happen before a protected branch receives the change.

Rules:

- Staging conflicts are resolved in `feature/*-staging` or `hotfix/*-staging`.
- Production conflicts are resolved in `feature/*` or `hotfix/*`.
- `develop-staging`, `staging`, `develop-main`, and `main` are not used as manual conflict resolution workspaces.
- PMs and reviewers should only merge branches that are clean and ready.
- If another pull request is merged first and a branch becomes conflicted, the developer who owns the branch updates it and resolves the conflict.

## Release Safety Rules

Production release safety depends on keeping staging integration separate from production candidates.

Rules:

- Do not merge `develop-staging` directly into `develop-main` unless every change in `develop-staging` is intentionally production-ready.
- Do not merge `staging` into `main`.
- Do not merge `feature/*-staging` into `develop-main` or `main`.
- Do not ship unfinished staging-only features to production.
- Production deploys should come from `main` or a release tag from `main`.

## Practical Summary

Normal feature:

```text
feature/name
feature/name-staging
```

Staging receives:

```text
feature/name-staging -> develop-staging -> staging
```

Production receives:

```text
feature/name -> develop-main -> main
```

The staging branch exists to absorb staging conflicts. The main feature branch remains the production candidate.
