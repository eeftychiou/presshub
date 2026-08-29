# Agent SDLC & Testing Guidelines

This document outlines the standard operating procedures, workflows, and verification tasks for all autonomous subagents contributing to the Presshub repository.

## 1. Local Development & Git Workflow
- Ensure you are operating within an isolated branch or worktree (`git checkout -b <type>/<issue-id>-<description>`).
- Implement the code addressing the requirements.
- Use conventional commits format for your commit messages (e.g., `fix(component): description (Fixes #ID)`).

## 2. Automated Verification Suite
Before any pull request is opened, you must run and pass the automated test suites:
- **Integration Tests:** `php dev-env/scripts/run-integration-tests.php`
- **Unit Tests:** `php presshub-ai-editor/tests/run-all-tests.php`
- **Syntax Linting:** Ensure all modified files pass PHP linting (`php -l`).

## 3. Visual Inspection & Screenshot Verification
**NEW:** As part of the QA pipeline, agents must perform a visual inspection of all frontend or admin UI changes in the local testing environment.
- Start the local development server if not already running.
- Navigate to the modified interface in a headless or automated browser context.
- Verify the UI layout, state changes, and component rendering match the requirements.
- **Record screenshots** of the "before" and "after" states (or just the final state of the new feature/fix).
- Attach these screenshots to the Pull Request or walkthrough artifact to provide visual proof that the fix renders correctly.
