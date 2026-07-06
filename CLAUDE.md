# Singularity's API

Admin (Auth) backend. Manages the Admin work of User Manage , product manage, Template Manage.
Loan Management System (CRM) backend. Manages the full lifecycle of home loans: origination.
Loan Origination System (LOS) backend. Manages the full lifecycle of home loans: underwriting, disbursement.
Loan Management System (LMS) backend. Manages the full lifecycle of home loans: disbursement, repayment schedules, collections, accounting, and compliance reporting.

## Tech Stack

- PHP 8.3, Laravel 12.0, MySQL (multi-database), MongoDB, Redis
- Auth: JWT (tymon/jwt-auth)
- Excel exports: maatwebsite/excel 3.x
- Cloud: AWS S3, SES, SQS
- Error tracking: Sentry + Rollbar
- CI/CD: GitLab CI with SonarQube
- Code style: StyleCI (Laravel preset)

## Architecture

Single Laravel app, API-only. All routes prefixed `/api/v1/`.

### Directory Map

- `app/Http/Controllers/V1/` — Domain-organized controllers (Cases/, Collections/, Accounting/, Credits/, Disbursements/, etc.)
- `app/Traits/V1/` — Core business logic traits. Files are large (RepaymentTraits ~120KB, CaseTraits ~100KB). Read only the methods you need.
- `app/Helpers/` — Static utility classes. `CommonHelper` is globally autoloaded via composer.
- `app/Models/V1/` — Eloquent models (~175). Tables prefixed with `s_` (e.g., `s_applications`, `s_repayment_schedule`).
- `app/Services/` — Third-party integration wrappers (Bureau, NACH, Banking, AccountAggregator, etc.)
- `app/Exports/` — Maatwebsite Excel export classes. Most implement `FromView`.
- `app/Classes/` — Core domain classes and integrations.
- `routes/admin.php` — Primary admin route file. `routes/api.php` for sourcing/partner API.

### Database Connections

Multiple MySQL databases in `config/database.php`:
- `mysql` — Primary LMS database
- `mysql_lending_core` — Lending core
- `mysql_bhn` — BHN partner database
- `mongodb` — MongoDB via laravel-mongodb

### Key Patterns

- Controllers use `Responder` trait for JSON responses: `$this->positiveResponse($message, $data)` / `$this->negativeResponse($message, $errorId, $errors)`
- Controllers mix multiple traits: `use Responder, CaseTraits, RepaymentTraits, ...`
- Models specify `$connection` and explicit `$table` names with `s_` prefix
- Route groups: admin routes use `auth:api` + `is_active` middleware, sourcing routes use `auth:api-sourcing`

## Commands

```bash
# Run tests
php artisan test
./vendor/bin/phpunit
./vendor/bin/phpunit --testsuite=Unit
./vendor/bin/phpunit --testsuite=Feature

# Syntax check
php -l path/to/file.php

# Clear caches
php artisan cache:clear && php artisan config:clear && php artisan route:clear

# List routes
php artisan route:list --path=api/v1/admin
```

## Code Style

- **Assignment operator**: Single space on both sides: `$var = $value`. Never aligned padding like `$var      = $value`.
- **Style enforcement**: StyleCI runs on push (Laravel preset). `unused_use` rule is disabled — do not add unused imports, but do not remove existing ones in files you did not author.
- **Response format**: Use `$this->positiveResponse($message, $data)` or `$this->negativeResponse($message, $errorId, $errors)` from the Responder trait.
- **Credentials**: Never hardcode. Use `env()` helper or config files.
- **New controllers**: Extend `Controller`, use `Responder` trait, place in `app/Http/Controllers/V1/{Domain}/`.
- **New models**: Set `$connection`, `$table` (with `s_` prefix), and `$fillable`.
- **Debug statements**: Never leave `dd()`, `dump()`, `var_dump()`, `print_r()`, or `Log::debug()` in committed code.
- **Type hints**: Use scalar type hints and return types on new methods. Avoid returning mixed `false`/`null` for errors — throw exceptions instead.

## Git Workflow

- Remote: GitLab (self-hosted)
- Main branch: `master`
- Branch name format follows `## Git Workflow` above: `<ticket-number>_<descriptive-name>_<project>_master` (e.g., `51912_insurance_changes_los_master`). Project segment is `crm`, `los`, or `lms` based on step 2.
- Merge via GitLab MRs into master
- Never push directly to master

## Planning Checklist (before implementing any feature)

1. Which controller domain? (Cases/, Collections/, Accounting/, Credits/, Disbursements/, Sourcing/, etc.)
2. Which route file? Admin → `routes/admin.php`. Partner/sourcing → `routes/api.php`.
3. Search `app/Traits/V1/` and `app/Helpers/` for reusable logic first.
4. Which DB connection is needed? (mysql, mysql_lending_core, mysql_bhn, mongodb)
5. Exports needed? (`app/Exports/`, implement `FromView`). Queued jobs? (`app/Jobs/`). Form requests? (`app/Http/Requests/`).
6. Never use emdash in any plans.
7. Plan files live at `.claude/plans/<feature-slug>/`: one `spec.md` per feature (the design contract), one `YYYY-MM-DD_progress.md` per session (never overwrite). No frontmatter, no index files; cross-link with relative markdown paths. When asked to "update the plan" without a named file, append a new dated `_progress.md` rather than editing the spec. Plans are gitignored — to find them, scope `Grep` with `path: .claude/plans/` or run `Glob .claude/plans/**/*.md`; project-wide grep will skip them.

## Debugging Protocol

- **Phase 1 — Root cause**: Parse error, read source, trace backward through call chain. Check models for correct `$connection`, `$table`, relationships.
- **Phase 2 — Pattern analysis**: Has this error occurred elsewhere? Is it a symptom of a deeper issue?
- **Phase 3 — Hypothesis**: State it clearly, find evidence. If it fails, form a new one — no random guess-and-check.
- **Phase 4 — Fix**: Target root cause, not symptom. Run `php -l` on changed files. Run tests if they exist.
- **Guardrail**: If 3+ fix attempts fail, STOP and question whether the approach is wrong.

## Working Practices

- **Search before writing**: Before creating new code, search `app/Traits/V1/`, `app/Helpers/`, and `app/Services/` for existing implementations. Reuse over reinvent.
- **Verify before claiming done**: Never say "should work" or "probably fine." Run the verification command, read the output, confirm it supports your claim.
- **Context management**: Use `/compact` at logical phase transitions (after research, after planning, after debugging) — not mid-task. Traits are 100KB+ and eat context fast.
- **Agent routing**: Use `@explorer` (Haiku) for codebase search, `@coder` (Sonnet) for implementation, `@planner` (Opus) for architecture, `@reviewer` (Opus) for code review. Slash commands `/plan`, `/review`, `/fix`, `/refactor` auto-delegate to the right agent.

## Gotchas

- Traits are massive. When editing `RepaymentTraits` or `CaseTraits`, read only the specific method — do not load the entire file.
- Table names use `s_` prefix everywhere. Check the model's `$table` property before writing queries.
- Multiple database connections: verify which `$connection` a model uses before writing cross-DB queries.
- `composer.lock` is gitignored — each environment resolves its own dependencies.
- `CommonHelper` is autoloaded globally — its static methods are available everywhere without imports.
- Replace arbitrary `sleep()` calls with condition-based polling when waiting for state changes.
- For route conventions, see `routes/admin.php` and `routes/api.php`.
- For database schema, see `config/database.php` and model `$table` / `$connection` properties.

## Ticket-to-MR Workflow
 
When the user provides an OpenProject story or feature ticket ID, follow this end-to-end workflow:
 
### 1. Fetch ticket from OpenProject
- Load the OpenProject MCP (`mcp__openproject-fastmcp`).
- Call `get_work_package` with the ticket ID.
- Read the ticket subject and description carefully.
 
### 2. Confirm target project directory
Ask the user which project directory the work belongs to:
- **CRM** — `C:\laragon\www\vCrm`
- **LOS** — `C:\laragon\www\vLos`
- **LMS** — `C:\laragon\www\vLms`
 
Use `AskUserQuestion` with these three options. Do not assume from the current working directory.
### 3. Branch setup (run before any code changes)
Execute in the chosen project directory:
```
git checkout master
git pull origin master
git checkout -b <ticket-number>_<descriptive-name>_<project>_master
git pull origin master
```
Branch name format follows `## Git Workflow` above: `<ticket-number>_<descriptive-name>_<project>_master` (e.g., `51912_insurance_changes_los_master`). Project segment is `crm`, `los`, or `lms` based on step 2.

### 4. Commit and push
```
git add <only the specific files you changed>
git commit -m "<message>"
git push origin <branch_name>
```
- Stage only the exact files modified during this ticket. List them explicitly by path.
- Never run `git add .`, `git add -A`, or `git add -u`. These can sweep in unrelated edits, untracked files, or secrets.
- Run `git status` first to confirm which files are yours; if there are pre-existing unrelated changes, leave them unstaged.
- Confirm the commit message with the user before running.
 
### 5. Create GitLab MR
- Load the GitLab MCP (`mcp__gitlab`).
- Call `create_merge_request` with:
  - **Target branch**: `master`
  - **Assignee**: saurabh.bhoma@singularitycredit.com
  - **Reviewers**: janish.jain@singularitycredit.com
  - **Title**: derived from ticket subject
  - **Description**: summary of changes + ticket link
 
### 6. Comment on OpenProject ticket
- Call `mcp__openproject-fastmcp__add_work_package_comment` on the original ticket with the MR URL returned from step 5.

 
### 7. Post Msg on Teams Group
- Load the Teams MCP (`mcp__teams-mcp`).
- Call `send_chat_message` with:
  - **chatId**: `19:c277f77c443c4d999d8c8a9e4f03b741@thread.v2` (Velocity Backend Team)
  - **format**: `markdown`
  - **message**: include ticket title, description summary, OpenProject ticket link, and GitLab MR link.

### Workflow guardrails
- Confirm with the user at the end of step 2 (project directory) and before step 4 (commit message).
- If any git step fails (merge conflict, dirty working tree), stop and report — do not force-push or discard work.
- If the ticket has no clear scope, ask the user for clarification before planning.
