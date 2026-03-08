# Release Process

## Overview

Releases are fully automated via **[semantic-release](https://semantic-release.gitbook.io/)**. You never manually set a version number — semantic-release reads your Git commit messages, decides what the next version should be, updates all version strings in the codebase, generates a changelog, creates a GitHub Release, and triggers the zip build. All of this is triggered by merging to `main`.

**Do not create GitHub Releases manually.** A manually created release skips semantic-release entirely, so no version numbers are updated, no CHANGELOG entry is written, and the zip may not contain the right code.

---

## Normal Development Workflow

```
dev branch  ──►  pull request to main  ──►  merge  ──►  CI runs semantic-release  ──►  GitHub Release  ──►  zip built
```

1. **Do all work on `dev`** (or a feature branch off `dev`).
2. **Open a pull request** from `dev` → `main`. The CI pipeline runs unit tests, integration tests, and coding standards checks on the PR.
3. **Merge the PR** into `main`. Use **"Squash and merge"** and write the squash commit message in Conventional Commits format (see below) — this single commit message determines the version bump.
4. **CI runs automatically** on the push to `main`. The `release` job waits for all tests to pass, then runs semantic-release, which:
   - Bumps the version in `plugin/static-social-hub.php`, `plugin/readme.txt`, and updates `CHANGELOG.md`
   - Commits those changes back to `main` with `[skip ci]` (to avoid a loop)
   - Creates a GitHub Release tagged `vX.Y.Z`
5. **The `release.yml` workflow fires** on the `release: published` event and attaches `static-social-hub.zip` to the release. The zip extracts to a `static-social-hub/` directory, ready for WordPress.

---

## The Two-Step Pipeline

### Step 1 — semantic-release (the `release` job in `ci.yml`)

Runs only on pushes to `main` after all tests pass. It:

1. Analyses commits since the last release tag using [Conventional Commits](https://www.conventionalcommits.org/).
2. Calculates the next version (`patch` / `minor` / `major`).
3. Updates version strings in:
   - `plugin/static-social-hub.php` — the `Version:` header and the `SSH_VERSION` constant
   - `plugin/readme.txt` — the `Stable tag:` line
4. Writes/updates `CHANGELOG.md`.
5. Commits those changes back to `main` with the message `chore(release): X.Y.Z [skip ci]`.
6. Creates a GitHub Release tagged `vX.Y.Z` with generated release notes.

### Step 2 — Build zip (`.github/workflows/release.yml`)

Fires automatically when semantic-release publishes the GitHub Release. It:

1. Checks out the tagged commit.
2. Runs `composer install --no-dev` to get production-only dependencies.
3. Copies `plugin/` to `static-social-hub/` and zips it — so when WordPress installs it, the plugin directory is named correctly.
4. Uploads `static-social-hub.zip` as an asset on the GitHub Release.

---

## Commit Message Convention

semantic-release determines the version bump entirely from commit messages. Use the **Conventional Commits** format:

| Commit prefix | Release type | Example |
|---|---|---|
| `fix:` | Patch (0.0.**x**) | `fix: prevent duplicate comment flood error` |
| `feat:` | Minor (0.**x**.0) | `feat: add widget dark mode support` |
| `feat!:` or `BREAKING CHANGE:` in footer | Major (**x**.0.0) | `feat!: rename REST endpoint` |
| `chore:`, `docs:`, `test:`, `refactor:`, `style:` | No release | `chore: update dependencies` |

If there are no `fix:` or `feat:` commits since the last release tag, semantic-release does nothing and no new release is created.

---

## What Gets Updated Automatically

You never need to edit these manually:

| File | What changes |
|---|---|
| `plugin/static-social-hub.php` | `Version:` header + `SSH_VERSION` constant |
| `plugin/readme.txt` | `Stable tag:` line |
| `CHANGELOG.md` | New release section prepended |

The commit that makes those changes carries `[skip ci]` to prevent a CI loop.

---

## Running semantic-release Locally (dry run)

Useful for debugging what version *would* be created without actually doing anything:

```bash
# Install Node dependencies first (only needed once)
npm install

# Dry run — no changes made, no tags created
GITHUB_TOKEN=ghp_yourtoken npx semantic-release --dry-run
```

You need a GitHub personal access token with **repo** scope.

Common errors:

| Error | Cause | Fix |
|---|---|---|
| `ENOGHTOKEN` | `GITHUB_TOKEN` not set | Export the env var before running |
| Tag/history errors | Shallow clone | Run `git fetch --unshallow` first |
| `Cannot push to protected branch` | Branch protection requires PRs | Use the PR workflow instead |

## Overview

Releases are fully automated via **[semantic-release](https://semantic-release.gitbook.io/)**. You never manually set a version number — semantic-release reads your Git commit messages, decides what the next version should be, updates all version strings in the codebase, generates a changelog, creates a GitHub Release, and triggers the zip build. All of this happens on the `main` branch.

**Do not create GitHub Releases manually.** A manually created release skips semantic-release entirely, so no version numbers are updated, no CHANGELOG entry is written, and the zip may not contain the right code.

---

## The Two-Step Pipeline

### Step 1 — semantic-release (triggered by push to `main`)

There is no dedicated CI job for this yet; semantic-release is meant to be run as part of CI on every push to `main` (see [Adding semantic-release to CI](#adding-semantic-release-to-ci) below). When it runs it:

1. Analyses commits since the last release using [Conventional Commits](https://www.conventionalcommits.org/).
2. Calculates the next version (`patch` / `minor` / `major`).
3. Updates version strings in:
   - `plugin/static-social-hub.php` — the `Version:` header line and the `SSH_VERSION` constant
   - `plugin/readme.txt` — the `Stable tag:` line
4. Writes/updates `CHANGELOG.md`.
5. Commits those changes back to `main` with the message `chore(release): X.Y.Z [skip ci]`.
6. Creates a GitHub Release tagged `vX.Y.Z` with generated release notes.

### Step 2 — Build zip (`.github/workflows/release.yml`, triggered by the GitHub Release)

When semantic-release publishes the GitHub Release in step 1, the `release.yml` workflow fires automatically on the `release: published` event. It:

1. Checks out the tagged commit.
2. Runs `composer install --no-dev` to get production-only dependencies.
3. Zips the `plugin/` directory into `static-social-hub.zip`.
4. Uploads the zip as an asset on the GitHub Release.

---

## Commit Message Convention

semantic-release determines the version bump entirely from commit messages. Use the **Conventional Commits** format:

| Commit prefix | Release type | Example |
|---|---|---|
| `fix:` | Patch (0.0.**x**) | `fix: prevent duplicate comment flood error` |
| `feat:` | Minor (0.**x**.0) | `feat: add widget dark mode support` |
| `feat!:` or `BREAKING CHANGE:` in footer | Major (**x**.0.0) | `feat!: rename REST endpoint` |
| `chore:`, `docs:`, `test:`, `refactor:`, `style:` | No release | `chore: update dependencies` |

If there are no `fix:` or `feat:` commits since the last release, semantic-release does nothing and no new release is created.

---

## Recommended Workflow

### Use pull requests

The recommended flow is:

1. Do all development on a feature or fix branch.
2. Open a pull request to `main`.
3. Merge via **"Squash and merge"** (or regular merge) — make sure the merge commit message follows Conventional Commits format.
4. semantic-release runs on the resulting push to `main` and creates the release if warranted.

**Direct pushes to `main` also work** if you keep commit messages in the conventional format, but PRs give you CI checks (unit tests, CS) before anything lands on `main`.

### Branch naming is not special

Branch names do not affect versioning. Only commit messages on `main` matter.

---

## Adding semantic-release to CI

The current `ci.yml` does not yet run semantic-release. Add a job like this:

```yaml
release:
  name: Release
  runs-on: ubuntu-latest
  needs: [unit-tests]          # only release if tests pass
  if: github.ref == 'refs/heads/main' && github.event_name == 'push'
  permissions:
    contents: write
    issues: write
    pull-requests: write
  steps:
    - uses: actions/checkout@v4
      with:
        fetch-depth: 0          # semantic-release needs full history
        persist-credentials: false

    - uses: actions/setup-node@v4
      with:
        node-version: 20

    - name: Install Node dependencies
      run: npm ci

    - name: Release
      env:
        GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
      run: npx semantic-release
```

> **`fetch-depth: 0` is required.** semantic-release walks the full Git history to find the previous release tag. Without it, it cannot determine what changed and will fail or produce incorrect results.

---

## Running a Release Locally

Running semantic-release locally is useful for debugging, but it will actually create tags and releases unless you use dry-run mode.

### Prerequisites

```bash
# Install Node dependencies (only needed once)
npm install
```

### Dry run (safe — no changes made)

```bash
GITHUB_TOKEN=ghp_yourtoken npx semantic-release --dry-run
```

This shows you exactly what version would be created and what commits are included, without touching GitHub or writing any files.

### Full local run (creates a real release)

```bash
GITHUB_TOKEN=ghp_yourtoken npx semantic-release
```

You need a GitHub personal access token with **repo** scope. The token must have permission to push to `main` and create releases.

Common errors when running locally:

| Error | Cause | Fix |
|---|---|---|
| `ENOGHTOKEN` | `GITHUB_TOKEN` not set | Export the env var before running |
| `ENOTINHISTORY` / tag errors | Shallow clone | Run `git fetch --unshallow` first |
| `Cannot push to protected branch` | Branch protection requires PRs | Run from CI instead, or temporarily allow admin push |
| `EGITNOPERMISSION` | Token lacks write access | Use a token with `repo` scope |

---

## What Gets Updated Automatically

You never need to edit these manually before a release — semantic-release handles them:

| File | What changes |
|---|---|
| `plugin/static-social-hub.php` | `Version:` header + `SSH_VERSION` constant |
| `plugin/readme.txt` | `Stable tag:` line |
| `CHANGELOG.md` | New release section prepended |

The commit that makes those changes is tagged `vX.Y.Z` and has `[skip ci]` in its message to prevent a CI loop.
