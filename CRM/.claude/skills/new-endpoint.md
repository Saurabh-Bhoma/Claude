---
name: New API Endpoint
description: Step-by-step guide for creating a new API endpoint following project conventions
---

# Create a New API Endpoint

Follow the controller, model, and route patterns defined in CLAUDE.md. This skill adds the step-by-step sequence:

## Sequence

1. **Determine domain** -- Cases, Collections, Accounting, Credits, Disbursements, Sourcing, etc.
2. **Check existing** -- search for similar endpoints before creating new ones.
3. **Create/update controller** in `app/Http/Controllers/V1/{Domain}/`
4. **Create Form Request** if input validation needed (`app/Http/Requests/`)
5. **Create/update Model** if needed (`app/Models/V1/`)
6. **Add route** to correct file (admin.php or api.php)
7. **Verify** -- `php -l` on all files, `php artisan route:list --path={new-route}` to confirm registration.
