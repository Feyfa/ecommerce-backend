# Backend Agent Instructions

## Repository Scope

This repository contains the backend application and API.

## Project Documentation

Repository documentation is available in the `docs/` directory.

Before answering or acting on a project-specific request, including analysis or changes:

1. Identify the feature and areas related to the task.
2. Search for and read the relevant documentation in `docs/`. Do not read every document unless the task requires it.
3. Inspect the related implementation, configuration, dependencies, database structures, tests, and repository state to verify that the documentation still reflects the current codebase.
4. Follow the architecture, patterns, and coding style already used in this repository.
5. Preserve the existing writing and coding style, including whitespace and formatting. Do not reformat or change style unless the task explicitly requests it or the change is required for correctness.
6. Update the relevant documentation when a change affects behavior, API contracts, the database, configuration, deployment, or the developer workflow.
7. Treat the current implementation and repository state as the primary evidence. Separate verified facts from unresolved assumptions and do not use unverified assumptions as the basis for a change or Git operation.
8. If the user interrupts, corrects, or asks for the instructions or situation to be reread, stop and repeat this preflight against the current state before continuing.

Review the relevant documentation before modifying endpoints, business logic, database schemas, migrations, authentication, authorization, queues, configuration, or API contracts.

Use four spaces for every indentation level in PHP source code and in other
backend code or configuration formats whose established convention permits it.
Keep indentation consistent within each file, follow PSR-12 for PHP, and do not
mix tabs, two-space indentation, and four-space indentation in the same scope.
Preserve a format's required or generated indentation when four spaces would
conflict with its syntax or authoritative tooling.

When adding, removing, renumbering, or otherwise changing checklist rows in
`docs/qa/`, review the complete checklist and reorder its IDs numerically before
finishing. Preserve meaningful section headings, but ensure the row sequence
across the document does not leave lower-numbered IDs after higher-numbered IDs.

## Released Task Content Consistency

- Finish every tracked change for a production-bound task on its main Jira task branch.
  Integrate and deploy that task content to staging before production, including tests,
  Markdown, agent instructions, lockfiles, and configuration. Any tracked edit after
  staging verification goes through staging integration and deployment again.
- Compare task content and overlapping hunks across the application branches. Attribute
  other differences to pending tasks and preserve their staging changes. Merge SHAs may
  differ. Resolve any unclear loss or change in the released task before promotion.
  Never reset staging to main, merge staging into main, or promote pending work to
  force whole-branch equality.
- Verify the source revisions active on both servers as well as the branch content.
  If tracked content is packaged into an image, deploy the final image to both
  environments. For deploy changes, verify the same released deploy revision on both VMs.
- Record content comparisons, pending-task exceptions, active revisions, and health
  evidence in Jira, PRs, or Actions. Keep release completion pending until the required
  deployment and consistency checks are verified.

## Local QA Documentation Boundary

Keep application `frontend/docs/qa/` and `backend/docs/qa/` for local QA. Record scenarios,
expected results, actual tests or review, and local coverage limitations before commit.
Label pending local checks as local and preserve unrelated historical QA.

Record post-commit CI, push, PR, merge, smoke tests, deployment, runtime health, image or
source revisions, and Jira completion in Jira, PRs, or Actions. These release checks
remain required.

## Manual QA Guidance And Evidence Reuse

- Map affected behavior and regression risks to pass criteria. Review existing tests,
  screenshots, and logs first. Track each scenario's evidence and status with its
  environment and relevant revision when known.
- Group related checks into a short sequence with the exact action, expected result,
  and minimum capture. Say which open scenarios each result proves and request only
  the evidence still missing.
- Reuse proof for the same behavior, environment, and relevant task content. Repeat only
  the checks affected by unclear evidence, a failure, a relevant change, or stale
  evidence. Explain the reason for the repeat.
- Match conclusions to what was observed. A screenshot of a refreshed cart can prove
  the visible quantity, selection, and total together. An HTTP 200 alone cannot prove
  business behavior. Required deployed manual checks still need deployed evidence.
- Follow the agreed QA scope. Obtain authorization before opening a browser,
  making real payments, or taking destructive or other state-changing actions while
  guiding QA. Stop asking for checks once required scenarios are proven, and report any
  remaining coverage limitations.

## Incremental Change Batches

Make file changes incrementally around one clear objective or behavior at a
time so that a human reviewer can understand the purpose and scope of each
batch.

- Do not combine independent objectives into one batch of edits.
- Before each batch, explain its objective, the files expected to change, and
  why each file belongs to that objective.
- Use the smallest practical file batch that keeps the implementation
  consistent and allows meaningful validation.
- When an objective requires only one file, edit and validate that file before
  proceeding to another independent objective.
- Multiple files may be changed together only when they implement or verify
  one shared contract or inseparable behavior. Explain that relationship before
  editing them.
- After each batch, inspect the actual diff and report the files changed, the
  resulting behavior, and the validation performed before starting another
  independent objective.
- Do not implement an entire multi-objective task first and explain all changes
  only after the full task is complete.
- If the required scope expands beyond the announced batch, explain the new
  file and reason before editing it.

## Related Repositories

The project may include separate frontend and deployment repositories. If a task affects another repository and that repository is available in the workspace, inspect its code and documentation as well. Do not assume that related repositories are always available or located at a specific path.

## Task Branch And Release Safety

Every backend implementation change must be associated with a clear Jira task
and a branch that follows the shared release flow in
`../deploy/docs/release-flow.md`.

- Before editing code, verify the Jira work type, responsible initials, issue
  key, and expected branch name.
- If any of those details are unclear, stop before editing, creating a branch,
  committing, pushing, or opening a pull request. Tell the user the expected
  branch format and ask for the missing Jira information.
- Never invent a Jira issue key or work-specific branch name.
- Do not implement application work directly on `main` or `staging`.
- Create a new task branch from the latest `main`, after checking local and
  remote branches for an existing branch for the same Jira task.
- The main task branch must exist and be checked out before the first code
  change. Verify the active branch before implementation and after every
  branch switch.
- If the active branch is `main`, `staging`, or unrelated to the Jira task,
  stop and create or check out the correct task branch before editing code.
- Use the main task branch as the production source. Use the matching
  `*-staging` branch only to integrate with `staging`.
- Never merge `staging` or a `*-staging` task branch into `main`, and never
  merge a regular task branch directly into `staging`.
- Before creating or refreshing a `*-staging` task branch, switch to the local
  `staging` branch and run `git pull --ff-only origin staging` as separate
  commands. Use the updated local `staging` branch as the merge source.
- A new `*-staging` task branch must be created from the completed main Jira
  task branch. Immediately before `git switch -c <task>-staging`, run
  `git branch --show-current` and require the output to exactly match `<task>`.
  Never create the branch while `main`, `staging`, or another branch is checked
  out.
- Do not create or reset a local task staging branch from `origin/staging`. Do
  not use `git switch -c/-C <task>-staging origin/staging` or
  `git checkout -b/-B <task>-staging origin/staging` for this workflow.
- Before integration merges, update the local `main` and `staging` branches
  with `git pull --ff-only` and use the refreshed local branch names as merge
  sources. Do not run `git merge origin/main`, `git merge origin/staging`, or
  `git merge origin/<task>`.
- After the completed main Jira task branch passes local validation, push it and
  wait for task-branch CI to pass before staging preparation.
- Refresh local `main` and `staging`, then merge local `main` into the main task
  branch. Compare the task branch HEAD before and after the merge. If HEAD
  changed, validate, push, and wait for CI again. If HEAD did not change, do not
  push again and retain the successful CI result for the unchanged commit.
- When the task staging branch already exists, merge the completed local main
  task branch into it, then merge the refreshed local `staging` branch. Do not
  recreate or reset the existing task staging branch.
- After validating the task staging branch, push it and wait for task-branch CI
  to pass before its pull request may be merged into `staging`.
- When a local merge must produce an integration commit, use
  `git merge --no-ff --no-edit <local-branch>` and let Git generate the merge
  message. Do not pass a custom `-m` message to `git merge`.
- Execute one Git command per terminal invocation. Do not chain Git commands
  with `&&`, `;`, command substitution, or a multiline shell block. Wait for
  each result before running the next command, and run status or log checks as
  separate commands.

### Working Branch End State And Release Completion

- After staging branch preparation, leave each participating application on its main Jira
  task branch after validating and pushing `*-staging`. Return deploy to its task branch
  after temporary inspection or refresh of `main`. Operations involving only long-lived
  branches finish on `main`.
- After each successful staging or production deployment and its health checks, confirm
  the working trees are safe to switch. Refresh local application `staging` and `main` and deploy
  `main` with `--ff-only`. Preserve local changes and task branches.
- While QA, fixes, or production promotion remain, finish on the main Jira task branch
  for each participating repo that has one. Use `main` when a repo has no task branch or
  the task's declared scope is complete. Production-bound completion requires production
  validation. Never force a switch, reset, or delete work to reach a checkout state.
- The runtime sources are application `staging` for staging, application `main` for
  production, and deploy `main` for workflows and VMs. Verify the final local checkout
  with `git branch --show-current` in every affected repo. Report failed pulls or checks.
- For a matching Jira issue, record release evidence. Move it to `Done` only after all
  required CI, merges, deployments, health checks, content checks, and local sync pass.
  Then rank it first in Done if a supported operation is available. Report a failed or
  unavailable ranking operation and leave opaque rank values to Jira.

## PHP Documentation and Comments

Add PHPDoc to every new or changed named PHP function or method. Document the primary
responsibility of new classes, interfaces, traits, and enums. Document anonymous
functions and closures when they contain important behavior or a non-obvious contract.

### PHPDoc Description Quality

Explain the purpose and contract in proportion to complexity. Cover relevant actors,
validation, business rules, side effects, failure behavior, and constraints such as race
conditions or compatibility. Document only information verified in the implementation
or project context.

### PHPDoc Tag Order and Spacing

Order the blocks as description, `@param`, `@return`, `@throws`, and other tags when needed.
Use one blank PHPDoc line after the description and between tag groups. Keep `@param`
tags adjacent. With no parameters, place `@return` after the description's blank line.

Use exactly one `@param` for each signature parameter, in order, and explain its use.
Use `@return` to explain every native return type, including `void` and `never`.
Use array shapes or generics when they add verified information. Document caller-facing
exceptions with `@throws`. Constructors and destructors can omit `@return` and have no
native return type.

### Native Types and Imports

Give new named functions and methods accurate native return types where PHP and
inherited contracts allow them. Verify return paths, callers, inherited and framework
contracts, and relevant tests first. Add a missing native type to an existing function
only when safe and in scope. Use a precise type whenever the implementation supports one.

Import class types with `use` and use short names in signatures and PHPDoc. Alias
conflicting names. Keep native types, documentation, default values, and exceptions
consistent with the implementation.

### Multi-step Logic

Use paired `// --- step N - <stage name> - start ---` and
`// --- step N - <stage name> - end ---` when PHP logic has distinct stages.
Group related statements under one concrete stage. Put the reason below its start marker
and separate stages with a blank line. Place the closing marker before `return` and
leave one blank line between them. A single-stage function gets a short intent comment.
Update existing step names or numbering when their behavior changes.

## Static Analysis

Run `composer analyse` for changes under `app/` and for dependency or configuration changes that can affect PHP type resolution. Treat the command as a blocking local and CI check.

The repository starts PHPStan through Larastan with the scope and level defined in `phpstan.neon.dist`. Keep analysis focused on the configured paths unless a separate task explicitly expands the adoption scope.

Fix a finding directly only when the correction can be proven without changing runtime control flow, queries, persistence, public API responses, side effects, framework contracts, or business rules. Prefer accurate PHPDoc generics, array shapes, relationship types, and other metadata that describe the existing implementation. Do not add a broad ignore rule, exclude a source path, weaken the configured level, or use an inaccurate `mixed` type merely to make analysis pass.

Existing findings that require behavior-sensitive changes may remain in `phpstan-baseline.neon` until a focused task resolves them. New findings must not be added to the baseline merely to pass CI. Do not regenerate the baseline wholesale; remove or narrow an entry when the related legacy finding is fixed, and keep unmatched-baseline reporting enabled so stale entries fail validation.

Do not silently baseline a finding that indicates a plausible security vulnerability, data corruption risk, or unsafe transaction behavior. Stop the affected change batch, preserve the evidence, and report the finding for an explicit scoped decision.

## Command And Approval Readability

Use one command per terminal invocation and wait for each Git or SSH command to finish.
Use the tool's working-directory option. Break long commands into one option or path per
line and keep complex logic in a small, reviewable script. Explain a temporary script
before running it.

Keep approval requests explicit about reads and writes. Explain what a destructive
action or remote write will do and why, then run it on its own. Never join operations
with shell separators, command substitution, or a multiline block.

## Git and Commit Workflow

Before proposing or creating a commit, confirm this repo and inspect its Git status.

Review the staged diff as the commit scope. If asked for a message from the working
tree before staging, inspect the scoped diff and relevant untracked files. Report any
uncertainty about the prospective scope. Switch or create branches, commit, push, or
open a PR only on explicit request.

Stage exact reviewed paths with `git add -- <path>`. For several paths, use one Git
command with continuations and one path per line. Avoid broad globs and whole-tree
staging. Check `git diff --cached --name-status`, `git diff --cached --stat`, and
`git diff --cached` as separate commands before committing.

### Commit Scope and Atomicity

Keep each commit within one repository and one reviewable purpose. Put related code,
tests, and docs together when they deliver the same behavior. Split independent changes
even if they share a branch. Describe inseparable changes when the dependency matters.

### Commit Message Structure

Use clear English and a Conventional Commits subject `<type>(<scope>): <summary>`.
Choose type and scope from the staged behavior. Use an imperative summary and limit the
whole subject to 72 characters. Wrap body prose at 72 characters and preserve commands,
URLs, paths, hashes, identifiers, and trailers. Reserve subject-only messages for
trivial changes.

Describe the motivation, changed behavior, and material decisions from the staged diff.
Group the body by behavior. Add API, migration, configuration, or release details when
relevant. Use `Validation:` for checks actually run, with their commands and results.
Include material gaps in `Limitations:` and keep credentials out of the message.
Use a `BREAKING CHANGE:` footer only for a breaking contract and explain the impact
and action consumers or operators need to take.

For backend changes, describe affected API contracts, security, persistence, transactions,
queues, migrations, and operational effects when relevant.

Use exactly one Codex co-author trailer when Codex materially contributed to the
staged content. Use `Co-authored-by: Codex (<model>, <reasoning effort>) <noreply@openai.com>`
when both details are verified. Omit the effort if only the model is verified. Use
`Co-authored-by: Codex <noreply@openai.com>` when metadata is unavailable or ambiguous.
Preserve valid trailers and place the Codex trailer last, after the body, footers, and
issue references, with a blank line before the trailer block.

### Commit Message Execution

Use the Ecommerce runbook's heredoc rule for commit input, including subject-only
messages and amendments that replace the message. Pass real newlines through a quoted
`'EOF'` delimiter with `git commit -F -`.

```bash
git commit -F - <<'EOF'
docs(workflow): describe the verified change
EOF
```

After committing, run `git log -1 --format=full` separately. Verify the repo, saved
message, line breaks, claims, and trailers.

## GitHub Tool Selection

Identify the repository and whether the operation is a pull request or GitHub Actions.
Use the tool specified for that operation.

### GitHub CLI Authentication Checks

Use standalone, read-only `gh auth status` when CLI authentication needs verification.
On the affected macOS host, a sandboxed check can miss the host keyring. Use the host
check directly when needed and the approval flow supports it. Explain that it is
read-only and scope any reusable approval to `["gh", "auth", "status"]`.

Report an unavailable host check and distinguish authentication failures from network
or environment errors. A sandbox-only failure leaves host auth unverified. Confirm the
active account, scopes, and required repo permissions. Keep tokens out of output and
documentation. Leave login state and settings alone during the check, and apply the usual
approvals to other commands.

### GitHub Pull Requests

Use `[TOK-X-staging] <description>` for application staging PRs and
`[TOK-X] <description>` for main PRs. Use the actual Jira key and the same description
for paired PRs. Deploy PRs target `main`.

Use the connected GitHub integration first. If unavailable or unsuccessful, explain
the limitation and use `gh pr`. Use `gh api` when a standard subcommand cannot perform
the operation. Browser interaction requires an explicit request.

Before a PR write, confirm the repo, PR number when applicable, and source and target
branches. For deploy PR writes, verify the GitHub account and repository permissions.
Verify the result through the integration or the stated fallback.

### GitHub Actions

Use the GitHub CLI for Actions. Run manual workflows with `gh workflow run` and
inspect runs with `gh run list`, `gh run view`, or `gh run watch`.
Before an operation that changes remote state, verify the GitHub account and its
repository and workflow permissions. If `gh` is unavailable or authentication fails,
report the limitation and stop the affected operation.
Browser work on application Actions requires an explicit request.

### CI And Deployment Monitoring

- Discover a run with `gh run list` and record its repo, run ID, workflow/event, source SHA,
  and attempt. For a queued or running run, prefer one watcher.
  `gh run watch <run-id> --repo <owner/repo> --exit-status --compact --interval 30`
- Keep the watcher in one resumable session with waits of at most 60 seconds. Use a
  task/run-specific log outside the repo when practical. Read a small relevant tail and
  give updates from known state. Leave polling to the healthy watcher.
- Recheck on discovery, final confirmation, interruption, ambiguous results, failure,
  or a user status request. On a disconnected or unsupported watcher, check the run
  once and resume one watcher if possible. Use bounded, compact CLI polling as the fallback.
- Confirm terminal status, conclusion, source SHA, and attempt with `gh run view`.
  A watcher exit or timeout does not establish the remote result or justify a retry.
  On failure inspect `gh run view <run-id> --log-failed` for the error and rollback or
  health evidence. On success read the logs needed for active revisions, images, and
  service/HTTP health.
- Reuse valid evidence for unchanged content and the same verification context.
  Keep push and PR CI separate. Record post-commit evidence in Jira, PRs, or Actions.
  Monitoring grants no permission to merge, retry, dispatch, or promote a release.
- Use subagents only with explicit authorization for distinct work.

See `../deploy/docs/release-flow.md` for release monitoring examples when available.
