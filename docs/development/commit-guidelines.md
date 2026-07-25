# Commit Guidelines: Separate Commits by Purpose

## Purpose

Keep each commit focused on one coherent purpose so project history remains easy to review, track, revert, and maintain.

## Core Rule

Commit changes separately when they serve different purposes, even when they are discovered or implemented during the same task.

For example, a Login feature change and an unrelated Audit Log fix must be committed separately. The fact that both changes were made in the same working session does not make them one change.

## When Changes Belong in One Commit

Keep related implementation, tests, and documentation in one commit when they are required to deliver the same behavior.

Examples:

- A product API fix, its focused test, and the product documentation update.
- A checkout endpoint change and the matching database migration when both are required for the same checkout behavior.

## When Changes Must Be Separate

Create separate commits for changes with different purposes, including:

- An unrelated bug fix found while implementing a feature.
- A refactor that is not necessary for the feature or fix being delivered.
- Formatting or cleanup unrelated to the intended behavior change.
- A change to a different feature, such as Login and Audit Log.

## Repository Boundaries

The frontend and backend are independent Git repositories. Keep their commits separate, even when one user-facing change requires updates in both repositories.

## Before Committing

- Review the staged diff and confirm it represents one purpose.
- Move unrelated changes out of the staging area before committing.
- Run the relevant validation for the staged change.
- Write a clear English commit message that describes the actual purpose and behavior change.

## Example

When a Login task reveals an Audit Log defect:

1. Commit the Login implementation, tests, and Login documentation together.
2. Commit the Audit Log fix, its validation, and Audit Log documentation separately.

This preserves a focused history and makes either change safer to review or revert later.
