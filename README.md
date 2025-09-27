# Laravel Smart Migrate

🚀 **Laravel Smart Migrate** is an advanced Artisan command that helps you analyze, reorder, and safely run your database migrations.  
It automatically detects dependencies, finds circular references, suggests fixes, and can even generate migration fixes for you.

## Features

- 🔍 **Analyze Migrations** – Detects table dependencies, foreign keys, indexes, and constraints.
- 🔄 **Smart Reordering** – Reorders migrations to avoid dependency errors.
- ⚠️ **Issue Detection** – Finds missing dependencies, duplicate indexes, naming issues, and potential performance problems.
- 🔧 **Auto-Fix & Suggestions** – Can fix issues automatically or generate fix files.
- 💾 **Backup Support** – Optionally creates a backup of migrations before applying fixes.
- 📊 **Detailed Reports** – Prints migration order, issues by severity, and dependency graphs.

## Installation

Copy the command into your Laravel app:

```bash
app/Console/Commands/SmartMigrateCommand.php
```

## Usage
Run the command with different modes:

```bash 
# Analyze migrations without running
php artisan database:migrate:smart --analyze

# Reorder migrations automatically
php artisan database:migrate:smart --reorder

# Fix detected issues (with backup)
php artisan database:migrate:smart --fix --backup

# Show dry-run execution order
php artisan database:migrate:smart --dry-run

# Generate fix files without applying
php artisan database:migrate:smart --generate-fixes
```


## Example Output
``` 
🚀 Smart Migration Analyzer v2.0 Starting...
🔍 Advanced dependency detection and auto-fixing enabled
📁 Loaded 15 migration files

📊 Advanced Migration Analysis Report
...

```

## Options
| Option             | Description                                 |
| ------------------ | ------------------------------------------- |
| `--analyze`        | Only analyze migrations without executing   |
| `--fix`            | Automatically fix detected issues           |
| `--dry-run`        | Show what would be executed without running |
| `--reorder`        | Automatically reorder migration files       |
| `--generate-fixes` | Generate fix files for issues               |
| `--backup`         | Create backup before applying fixes         |


## Roadmap
 - Improve column type consistency checks
 - Add support for raw SQL dependency parsing
 - Export dependency graph as a visual diagram

 ## License
 MIT License

``` text
MIT License

Copyright (c) 2025 Azozz ALFiras

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

```
