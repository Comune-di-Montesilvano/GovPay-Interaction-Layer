---
name: new-migration
description: Create the next numbered SQL migration file in migrations/. Pass the descriptive name as argument (snake_case, no .sql extension). Example: /new-migration add_foo_column
disable-model-invocation: true
---

Create the next numbered SQL migration file.

## Steps

1. List the `migrations/` directory. Find all files matching the pattern `\d{3}_*.sql`. Extract the numeric prefix from each, find the maximum.
2. Next number = max + 1, zero-padded to 3 digits (e.g. 025, 026).
3. Filename = `{NNN}_{argument}.sql` where `{argument}` is the snake_case name the user passed.
4. Create the file at `migrations/{NNN}_{argument}.sql` with this exact header:

```sql
-- Migration {NNN}: {argument}
-- Date: {YYYY-MM-DD}
--
```

5. Report the full filename created.
6. Remind the user that migrations are applied automatically by the DB container on first start, or manually via:
   ```
   docker cp migrations/{filename} gil-db:/tmp/{filename}
   docker exec gil-db mariadb -uroot -p"$DB_ROOT_PASSWORD" govpay < /tmp/{filename}
   ```
