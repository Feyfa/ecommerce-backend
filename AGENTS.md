# Backend Agent Instructions

## Repository Scope

This repository contains the backend application and API.

## Project Documentation

Repository documentation is available in the `docs/` directory.

Before changing code or configuration:

1. Identify the feature and areas related to the task.
2. Search for and read the relevant documentation in `docs/`. Do not read every document unless the task requires it.
3. Inspect the related implementation, configuration, dependencies, database structures, and tests to verify that the documentation still reflects the current codebase.
4. Follow the architecture, patterns, and coding style already used in this repository.
5. Update the relevant documentation when a change affects behavior, API contracts, the database, configuration, deployment, or the developer workflow.

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

## PHP Documentation and Comments

Every named PHP function or method that is added or changed must have a PHPDoc block, even when its name, native parameter types, or native return type appear self-explanatory. New classes, interfaces, traits, and enums must also have PHPDoc that explains their primary responsibility.

Anonymous functions, closures, and arrow functions do not require PHPDoc unless they contain important context, behavior, or a non-obvious contract.

### PHPDoc Description Quality

PHPDoc descriptions must explain the function's purpose and contract without requiring the reader to infer them from the function name. Documentation must be proportional to complexity and, when relevant, explain:

- the data, actor, or context being processed
- validation and business rules
- state changes, persistence, external calls, and other side effects
- invariants, constraints, edge cases, race conditions, or regressions being prevented
- the meaning of the result and important failure behavior

Simple functions may use a concise description when their behavior is genuinely straightforward. Multi-stage or non-obvious functions must use multiple sentences or paragraphs when necessary. Never invent behavior, side effects, exceptions, or constraints that cannot be verified from the implementation and project context.

### PHPDoc Tag Order and Spacing

Use this order consistently:

1. primary description
2. additional explanation when needed
3. all `@param` tags
4. the `@return` tag
5. all relevant `@throws` tags
6. other tags only when necessary

Use one blank PHPDoc line between the description and the first tag. Keep all `@param` tags adjacent with no blank lines between them. Use exactly one blank PHPDoc line between the final `@param` and `@return`, and one blank PHPDoc line between `@return` and `@throws` when `@throws` is present. When a function has no parameters, place `@return` directly after the description's single separating blank line.

Every signature parameter must have exactly one `@param` tag. Parameter names and order must exactly match the signature, types must remain compatible with the implementation, and each tag must explain the parameter's meaning or use rather than merely repeat its name.

Every named function or method with a native return type must have an accurate `@return` tag, including `void` and `never`. The tag must explain the meaning, structure, or behavior of the returned value. Use a more specific PHPDoc type, such as an array shape or generic collection, when it adds verified information that the native type cannot express.

Document relevant caller-facing exceptions with `@throws`. Constructors and destructors do not use native return type declarations and do not need a forced `@return` tag.

### Native Types and Imports

Every new named function or method must declare an accurate native return type when the supported PHP version and inherited contract allow it. Determine the type from every return path, caller, interface, parent class, trait, framework contract, and related test before adding it. Do not use `mixed` merely to satisfy this rule when a more accurate type can be proven.

Do not force a return type when PHP forbids it or when it would break an inherited or framework contract. When changing an existing function without a native return type, add one only when it can be determined safely and the change remains within task scope.

Import class types with `use` statements and use their short names in signatures and PHPDoc. Avoid fully qualified class names such as `\Illuminate\Database\Eloquent\Relations\BelongsTo` in the function declaration when `BelongsTo` can be imported without ambiguity. Use an explicit alias when two imported classes share the same short name.

Keep PHPDoc, native parameter types, native return types, default values, exceptions, and implementation behavior synchronized. Update or remove stale descriptions and tags whenever a function's signature or responsibility changes.

### Multi-step Logic

Functions with multiple distinct stages must use paired `step start/end` comments. Number steps consistently, keep every start and end marker paired, and describe the responsibility of each stage in concise, specific language.

```php
/**
 * Validates and processes an operation in distinct stages.
 *
 * @return void The operation applies its side effects directly.
 */
public function process(): void
{
    // --- step 1 - start - validate the operation input
    // ...
    // --- step 1 - end - validate the operation input

    // --- step 2 - start - persist the validated state
    // ...
    // --- step 2 - end - persist the validated state
}
```

Do not force step comments into a simple function that has no meaningful internal stages. Preserve existing step comments that remain accurate and update their descriptions or numbering when behavior changes.

Add contextual comments when they explain intent, business rules, state relationships, side effects, compatibility constraints, edge cases, race conditions, or regressions that are not obvious from the code. Prefer comments that explain why the logic exists and avoid comments that merely translate syntax.

## Git and Commit Workflow

Before proposing or creating a commit:

1. Confirm that Git operations are being performed in this repository.
2. Inspect `git status` and use the staged diff as the primary source when files are staged. Review `git diff --cached --stat` and `git diff --cached`, not only the changed file names.
3. If nothing is staged and the requested scope is the working tree, inspect the explicitly scoped actual diff and relevant untracked content. State when the prospective commit scope cannot yet be determined precisely.
4. Understand the API contract, business rules, persistence effects, transaction boundaries, security impact, configuration, migrations, documentation, and validation represented by that diff.
5. Keep unrelated changes out of the commit and never include changes from another repository. Recommend splitting the scope when the diff contains independently reviewable purposes.
6. Do not switch branches, create branches, commit, push, or open a pull request unless the user explicitly requests that action.

When staging a commit, add only the explicitly reviewed files with exact paths.
Use `git add -- <file>` for each intended file, or list several exact paths in
one command. Never use `git add -A`, `git add .`, `git add --all`, or broad
globs. After staging, inspect `git diff --cached --name-status`,
`git diff --cached --stat`, and `git diff --cached` so the approval clearly
shows which files will be committed.

### Commit Scope and Atomicity

A branch does not define a single commit scope. If a branch or working tree contains changes for multiple tasks, tickets, or independently reviewable purposes, inspect and stage each scope separately and generate one commit message from that scope's staged diff. Changes for one task must not absorb an unrelated feature, fix, refactor, configuration update, migration, test, or documentation change merely because they exist on the same branch.

A detailed commit message is not a substitute for an atomic commit. Recommend separate commits when the available changes do not form one cohesive purpose. Keep changes together only when they implement one inseparable behavior or when splitting them would create an invalid or materially misleading intermediate state; describe that dependency when it is not obvious.

The commit message must describe only content included in its verified scope. Exclude unstaged changes outside that scope, changes from another repository, unfinished implementation, future plans, and claims inferred only from task names, documentation, or earlier conversation.

Use English with correct grammar for commit messages. Follow the Conventional Commits style already used by this repository, including an appropriate type and scope when applicable. Inspect recent Git history when the established type, scope, or wording convention is unclear.

The summary must describe the actual high-level behavior change and remain specific enough for code review, Git history, debugging, deployment analysis, and revert operations. Choose the type and scope from the purpose of the change rather than a directory name. Do not derive the summary only from file names, branch names, task labels, or earlier conversation.

Use imperative mood for the summary when it matches the repository convention. For a complex change, follow the summary with a concise context or motivation paragraph that explains the problem, purpose, or high-level approach without repeating the summary.

Match commit-message detail to the staged scope:

- A small, single-purpose change may use only a precise summary.
- A medium change should normally include a short context paragraph and the main related behaviors.
- A complex or cross-cutting change must include a summary, concise context, and a body grouped by behavior or subsystem. Represent every major area included in the commit instead of compressing several API, persistence, security, or configuration changes into a generic bullet.

For complex backend commits, group details using headings derived from the actual diff, such as API contracts, business rules, database, transactions, queues, integrations, configuration, security, compatibility, migrations, or documentation. Explain request and response changes, status or error contracts, ownership and validation boundaries, schema and rollback implications, concurrency controls, runtime variables, and operational requirements when relevant. Group by behavior rather than listing every controller, service, model, migration, or test file.

Mention a file name only when its identity has operational or reviewer significance, such as a specific migration, workflow, environment template, or configuration file. Never include secret or credential values in a commit message.

### Commit Message Structure

Use this structure as an adaptive framework for medium and complex commits:

```text
<type>(<scope>): <summary>

<optional context or motivation paragraph>

<backend behavior or subsystem group>:
- <major behavior change>
- <major behavior change>

<another relevant behavior or subsystem group>:
- <major behavior change>

<optional technical or impact sections>

Validation:
- <actual automated test or manual verification>

Limitations:
- <verified limitation or unvalidated area>

<optional breaking-change and issue footers>

<optional attribution trailers>
```

Do not apply this framework rigidly. Omit empty or irrelevant sections, derive group names from the actual diff, and allow a small single-purpose commit to contain only a precise summary.

Use conditional sections such as `Technical details:`, `Database:`, `API:`, `Configuration:`, `Deployment:`, `Compatibility:`, `Security:`, `Documentation:`, `Validation:`, and `Limitations:` only when the staged change supports them. Do not add empty or ceremonial sections. Include a limitation when an important database engine, queue worker, integration credential, migration path, deployment step, or production-only behavior remains unverified.

Add a `Validation:` section only when the listed checks were actually executed. State the exact relevant commands or checks, do not claim that a full suite passed when only part of it ran, and report limitations honestly.

Distinguish automated tests from manual API or operational verification. Do not present a failed command, an unavailable check, or an inferred result as successful validation. Add `Limitations:` when an important database engine, queue worker, integration credential, migration or rollback path, environment, risk, assumption, or intentionally excluded scope remains unverified; do not add the section ceremonially.

Use a `BREAKING CHANGE:` footer only when the commit introduces a genuinely non-backward-compatible contract or behavior. Explain the previous contract, the new contract, and any action consumers must take; do not use the footer merely to emphasize a large change.

Add exactly one Codex co-author trailer only when Codex materially analyzed, wrote, or changed content included in the commit. Do not add it merely because Codex generated or refined the commit message. When the active model and reasoning effort are verified by authoritative session metadata, use `Co-authored-by: Codex (<model>, <reasoning effort>) <noreply@openai.com>`; when only the model is verified, omit the reasoning effort. Never infer or guess either value. If the metadata is unavailable or ambiguous, use `Co-authored-by: Codex <noreply@openai.com>`.

Preserve other valid trailers and do not add a duplicate Codex trailer. Separate trailers from preceding content with a blank line, do not format them as bullets, and place the Codex trailer at the end of the commit message after the body, conditional sections, `BREAKING CHANGE:` footer, and issue references.

Before presenting or creating the commit message, verify that the repository and staged scope are correct, every major API or subsystem behavior in scope is represented, each claim is supported by the diff or executed validation, English grammar is sound, no empty section remains, relevant limitations are disclosed, and footers and trailers are correctly ordered.

For multi-line commit messages, use real newline characters. Do not place literal `\n` sequences inside `git commit -m` arguments. Prefer `git commit -F -` with a heredoc or another method that preserves the intended line breaks.

After creating a commit, run:

```bash
git log -1 --format=full
```

Verify that the commit is in the correct repository, the summary and body are accurate, sections and bullet points have the intended line breaks, no literal `\n` text was stored, and validation claims match checks that were actually run. If the message is malformed and the commit has not been pushed, correct it when doing so is safe.

## GitHub Pull Requests

When the user asks to create, open, update, or otherwise operate a pull request, use the GitHub API through the connected GitHub integration. Do not use the GitHub website through browser automation for pull request operations unless the API is unavailable or the user explicitly requests browser-based interaction.
