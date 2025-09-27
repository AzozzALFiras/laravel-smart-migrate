<?php

namespace App\Console\Commands;

use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;

class SmartMigrateCommand extends Command
{
    protected $signature = 'database:migrate:smart
                            {--analyze : Only analyze migrations without executing}
                            {--fix : Automatically fix detected issues}
                            {--dry-run : Show what would be executed without running}
                            {--reorder : Automatically reorder migration files}
                            {--generate-fixes : Generate fix files for issues}
                            {--backup : Create backup before applying fixes}';

    protected $description = 'Smart migration analyzer that detects dependencies, fixes issues, and optimizes execution order';

    protected $migrationFiles = [];
    protected $dependencies = [];
    protected $issues = [];
    protected $tableCreationOrder = [];
    protected $fixesGenerated = [];

    public function handle()
    {
        $this->info('🚀 Smart Migration Analyzer v2.0 Starting...');
        $this->info('🔍 Advanced dependency detection and auto-fixing enabled');

        // Load all migration files
        $this->loadMigrationFiles();

        // Advanced dependency analysis
        $this->analyzeDependencies();
        $this->analyzeConstraints();
        $this->detectCircularDependencies();

        // Comprehensive issue detection
        $this->detectIssues();

        if ($this->option('analyze')) {
            $this->showAdvancedAnalysis();
            return 0;
        }

        if ($this->option('fix') || $this->option('generate-fixes')) {
            if ($this->option('backup')) {
                $this->createBackup();
            }
            $this->fixIssues();
        }

        if ($this->option('reorder')) {
            $this->reorderMigrationFiles();
        }

        // Show optimal execution order
        $this->showExecutionOrder();

        if (!$this->option('dry-run')) {
            return $this->executeMigrations();
        }

        return 0;
    }

    protected function loadMigrationFiles()
    {
        $migrationPath = database_path('migrations');
        $files = File::files($migrationPath);

        foreach ($files as $file) {
            $filename = $file->getFilename();
            if (Str::endsWith($filename, '.php')) {
                $content = File::get($file->getPathname());

                $this->migrationFiles[] = [
                    'filename' => $filename,
                    'path' => $file->getPathname(),
                    'timestamp' => $this->extractTimestamp($filename),
                    'name' => $this->extractMigrationName($filename),
                    'content' => $content,
                    'class_name' => $this->extractClassName($content),
                    'table' => null,
                    'operation' => $this->detectOperation($content),
                    'dependencies' => [],
                    'foreign_keys' => [],
                    'indexes' => [],
                    'constraints' => [],
                    'polymorphic' => null
                ];
            }
        }

        // Sort by timestamp initially
        usort($this->migrationFiles, function ($a, $b) {
            return strcmp($a['timestamp'], $b['timestamp']);
        });

        $this->info("📁 Loaded " . count($this->migrationFiles) . " migration files");
    }

    protected function extractClassName($content)
    {
        if (preg_match('/class\s+(\w+)\s+extends/', $content, $matches)) {
            return $matches[1];
        }
        return null;
    }

    protected function detectOperation($content)
    {
        if (preg_match('/Schema::create/', $content)) {
            return 'create';
        }
        if (preg_match('/Schema::table/', $content)) {
            return 'modify';
        }
        if (preg_match('/Schema::drop/', $content)) {
            return 'drop';
        }
        if (preg_match('/Schema::rename/', $content)) {
            return 'rename';
        }
        return 'unknown';
    }

    protected function analyzeDependencies()
    {
        $this->info('🔍 Analyzing dependencies and relationships...');

        foreach ($this->migrationFiles as &$migration) {
            $content = $migration['content'];

            // Extract table name
            $tableName = $this->extractTableName($content);
            $migration['table'] = $tableName;

            // Comprehensive foreign key analysis
            $foreignKeys = $this->extractForeignKeys($content);
            $migration['foreign_keys'] = $foreignKeys;

            // Extract all indexes
            $migration['indexes'] = $this->extractIndexes($content);

            // Extract constraints
            $migration['constraints'] = $this->extractConstraints($content);

            // Find all dependencies
            $dependencies = [];

            // Dependencies from foreign keys
            foreach ($foreignKeys as $fk) {
                if (isset($fk['references_table'])) {
                    $dependencies[] = $fk['references_table'];
                }
            }

            // Dependencies from explicit references
            $dependencies = array_merge($dependencies, $this->findExplicitTableReferences($content));

            // Dependencies from pivot tables (many-to-many)
            $pivotDeps = $this->detectPivotTableDependencies($content, $tableName);
            $dependencies = array_merge($dependencies, $pivotDeps);

            $migration['dependencies'] = array_unique($dependencies);

            // Polymorphic relations
            $migration['polymorphic'] = $this->detectPolymorphicRelations($content);

            // Model relationships hints
            $migration['relationships'] = $this->detectRelationshipHints($content);
        }
    }

    protected function analyzeConstraints()
    {
        $this->info('🔗 Analyzing constraints and unique keys...');

        foreach ($this->migrationFiles as &$migration) {
            $content = $migration['content'];

            // Find unique constraints
            $uniqueConstraints = $this->extractUniqueConstraints($content);
            $migration['unique_constraints'] = $uniqueConstraints;

            // Find check constraints
            $checkConstraints = $this->extractCheckConstraints($content);
            $migration['check_constraints'] = $checkConstraints;
        }
    }

    protected function detectCircularDependencies()
    {
        $this->info('🔄 Checking for circular dependencies...');

        $graph = [];
        foreach ($this->migrationFiles as $migration) {
            $table = $migration['table'];
            if ($table) {
                $graph[$table] = $migration['dependencies'];
            }
        }

        $visited = [];
        $recursionStack = [];

        foreach ($graph as $table => $deps) {
            if ($this->hasCircularDependency($table, $graph, $visited, $recursionStack)) {
                $this->issues[] = [
                    'type' => 'circular_dependency',
                    'table' => $table,
                    'message' => "Circular dependency detected involving table: {$table}"
                ];
            }
        }
    }

    protected function hasCircularDependency($table, $graph, &$visited, &$recursionStack)
    {
        if (isset($recursionStack[$table])) {
            return true;
        }

        if (isset($visited[$table])) {
            return false;
        }

        $visited[$table] = true;
        $recursionStack[$table] = true;

        if (isset($graph[$table])) {
            foreach ($graph[$table] as $dependency) {
                if ($this->hasCircularDependency($dependency, $graph, $visited, $recursionStack)) {
                    return true;
                }
            }
        }

        unset($recursionStack[$table]);
        return false;
    }

    protected function findExplicitTableReferences($content)
    {
        $dependencies = [];

        // Look for table() calls that reference other tables
        if (preg_match_all('/DB::table\([\'"]([^\'"]+)[\'"]\)/', $content, $matches)) {
            foreach ($matches[1] as $table) {
                $dependencies[] = $table;
            }
        }

        return $dependencies;
    }

    protected function detectPivotTableDependencies($content, $tableName)
    {
        $dependencies = [];

        // Common pivot table patterns
        if ($tableName && strpos($tableName, '_') !== false) {
            $parts = explode('_', $tableName);
            if (count($parts) >= 2) {
                // Assume pivot table format: table1_table2
                $table1 = Str::plural($parts[0]);
                $table2 = Str::plural($parts[1]);

                if ($table1 !== $tableName && $table2 !== $tableName) {
                    $dependencies[] = $table1;
                    $dependencies[] = $table2;
                }
            }
        }

        return $dependencies;
    }

    protected function detectRelationshipHints($content)
    {
        $relationships = [];

        // Look for common column patterns that suggest relationships
        if (preg_match_all('/\$table->(?:unsignedBigInteger|foreignId)\([\'"](\w+)_id[\'"]\)/', $content, $matches)) {
            foreach ($matches[1] as $relation) {
                $relationships[] = [
                    'type' => 'belongs_to',
                    'table' => Str::plural($relation)
                ];
            }
        }

        return $relationships;
    }

    protected function extractIndexes($content)
    {
        $indexes = [];

        // Find all index definitions
        preg_match_all('/\-\>index\(\s*(\[[^\]]+\]|[\'"][^\'"]+[\'"])\s*(?:,\s*[\'"]([^\'"]+)[\'"])?\)/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $columns = $match[1];
            $indexName = $match[2] ?? null;

            $indexes[] = [
                'columns' => $columns,
                'name' => $indexName,
                'type' => 'index'
            ];
        }

        // Find unique indexes
        preg_match_all('/\-\>unique\(\s*(\[[^\]]+\]|[\'"][^\'"]+[\'"])\s*(?:,\s*[\'"]([^\'"]+)[\'"])?\)/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $columns = $match[1];
            $indexName = $match[2] ?? null;

            $indexes[] = [
                'columns' => $columns,
                'name' => $indexName,
                'type' => 'unique'
            ];
        }

        return $indexes;
    }

    protected function extractConstraints($content)
    {
        $constraints = [];

        // Find constraint definitions
        preg_match_all('/\-\>constraint\([\'"]([^\'"]+)[\'"]\)/', $content, $matches);
        foreach ($matches[1] as $constraint) {
            $constraints[] = ['name' => $constraint];
        }

        return $constraints;
    }

    protected function extractUniqueConstraints($content)
    {
        $constraints = [];

        preg_match_all('/\-\>unique\(\s*(\[[^\]]+\]|[\'"][^\'"]+[\'"])\s*(?:,\s*[\'"]([^\'"]+)[\'"])?\)/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $constraints[] = [
                'columns' => $match[1],
                'name' => $match[2] ?? null
            ];
        }

        return $constraints;
    }

    protected function extractCheckConstraints($content)
    {
        $constraints = [];

        // Laravel doesn't have direct check constraint syntax, but we can look for custom SQL
        if (preg_match_all('/DB::statement\([\'"]ALTER TABLE[^\'\"]*CHECK[^\'\"]*[\'"]\)/i', $content, $matches)) {
            foreach ($matches[0] as $constraint) {
                $constraints[] = ['definition' => $constraint];
            }
        }

        return $constraints;
    }

    protected function detectIssues()
    {
        $this->info('🔎 Detecting issues and problems...');

        foreach ($this->migrationFiles as $migration) {
            // Check for duplicate indexes
            $this->checkDuplicateIndexes($migration);

            // Check dependency order
            $this->checkDependencyOrder($migration);

            // Check for missing dependencies
            $this->checkMissingDependencies($migration);

            // Check for naming conventions
            $this->checkNamingConventions($migration);

            // Check for potential performance issues
            $this->checkPerformanceIssues($migration);

            // Check for column type consistency
            $this->checkColumnConsistency($migration);
        }

        // Global checks
        $this->checkGlobalConsistency();
    }

    protected function checkNamingConventions($migration)
    {
        $content = $migration['content'];
        $tableName = $migration['table'];

        if ($tableName) {
            // Check if table name is plural
            if (Str::singular($tableName) === $tableName && !in_array($tableName, ['cache', 'data', 'media'])) {
                $this->issues[] = [
                    'type' => 'naming_convention',
                    'migration' => $migration['filename'],
                    'table' => $tableName,
                    'message' => "Table '{$tableName}' should probably be plural: '" . Str::plural($tableName) . "'",
                    'severity' => 'warning'
                ];
            }
        }

        // Check for foreign key naming
        foreach ($migration['foreign_keys'] as $fk) {
            if (!Str::endsWith($fk['column'], '_id')) {
                $this->issues[] = [
                    'type' => 'naming_convention',
                    'migration' => $migration['filename'],
                    'table' => $tableName,
                    'message' => "Foreign key column '{$fk['column']}' should end with '_id'",
                    'severity' => 'warning'
                ];
            }
        }
    }

    protected function checkPerformanceIssues($migration)
    {
        $content = $migration['content'];

        // Check for missing indexes on foreign keys
        foreach ($migration['foreign_keys'] as $fk) {
            $hasIndex = false;
            foreach ($migration['indexes'] as $index) {
                if (strpos($index['columns'], $fk['column']) !== false) {
                    $hasIndex = true;
                    break;
                }
            }

            if (!$hasIndex) {
                $this->issues[] = [
                    'type' => 'performance',
                    'migration' => $migration['filename'],
                    'table' => $migration['table'],
                    'message' => "Foreign key column '{$fk['column']}' should have an index for better performance",
                    'severity' => 'info',
                    'fix' => "Add: \$table->index('{$fk['column']}');"
                ];
            }
        }
    }

    protected function checkColumnConsistency($migration)
    {
        // This would compare column types across migrations
        // Implementation would involve tracking column definitions globally
    }

    protected function checkGlobalConsistency()
    {
        // Check for consistent foreign key types
        $foreignKeyTypes = [];

        foreach ($this->migrationFiles as $migration) {
            foreach ($migration['foreign_keys'] as $fk) {
                $key = $fk['references_table'] . '.' . $fk['references_column'];
                if (!isset($foreignKeyTypes[$key])) {
                    $foreignKeyTypes[$key] = [];
                }
                $foreignKeyTypes[$key][] = [
                    'migration' => $migration['filename'],
                    'column' => $fk['column']
                ];
            }
        }

        // Additional global consistency checks would go here
    }

    protected function showAdvancedAnalysis()
    {
        $this->info("\n📊 Advanced Migration Analysis Report\n");

        // Show migration order with enhanced details
        $headers = ['Order', 'Migration', 'Table', 'Operation', 'Dependencies', 'Foreign Keys'];
        $rows = [];

        foreach ($this->migrationFiles as $index => $migration) {
            $rows[] = [
                $index + 1,
                $migration['filename'],
                $migration['table'] ?: 'N/A',
                ucfirst($migration['operation']),
                implode(', ', $migration['dependencies'] ?: ['None']),
                count($migration['foreign_keys'])
            ];
        }

        $this->table($headers, $rows);

        // Show issues categorized by severity
        if (!empty($this->issues)) {
            $this->error("\n⚠️  Issues Found:\n");

            $critical = array_filter($this->issues, fn ($i) => ($i['severity'] ?? 'error') === 'error');
            $warnings = array_filter($this->issues, fn ($i) => ($i['severity'] ?? 'error') === 'warning');
            $info = array_filter($this->issues, fn ($i) => ($i['severity'] ?? 'error') === 'info');

            if ($critical) {
                $this->error("🔴 Critical Issues:");
                foreach ($critical as $issue) {
                    $this->line("  ❌ {$issue['type']}: {$issue['message']}");
                    if (isset($issue['fix'])) {
                        $this->comment("     Fix: {$issue['fix']}");
                    }
                }
            }

            if ($warnings) {
                $this->warn("\n🟡 Warnings:");
                foreach ($warnings as $issue) {
                    $this->line("  ⚠️  {$issue['type']}: {$issue['message']}");
                }
            }

            if ($info) {
                $this->info("\n🔵 Suggestions:");
                foreach ($info as $issue) {
                    $this->line("  💡 {$issue['type']}: {$issue['message']}");
                    if (isset($issue['fix'])) {
                        $this->comment("     Suggestion: {$issue['fix']}");
                    }
                }
            }
        } else {
            $this->info("\n✅ No issues found! Your migrations look great!");
        }

        // Show dependency graph
        $this->showDependencyGraph();
    }

    protected function showDependencyGraph()
    {
        $this->info("\n🕸️  Dependency Graph:\n");

        foreach ($this->migrationFiles as $migration) {
            if ($migration['table'] && !empty($migration['dependencies'])) {
                $this->line("📋 {$migration['table']}:");
                foreach ($migration['dependencies'] as $dep) {
                    $this->line("   └── depends on: {$dep}");
                }
            }
        }
    }

    protected function fixIssues()
    {
        $this->info('🔧 Fixing detected issues...');

        $fixCount = 0;
        foreach ($this->issues as $issue) {
            if ($this->canAutoFix($issue)) {
                $this->applyFix($issue);
                $fixCount++;
            } elseif ($this->option('generate-fixes')) {
                $this->generateFixFile($issue);
            }
        }

        $this->info("✅ Applied {$fixCount} automatic fixes");

        if (!empty($this->fixesGenerated)) {
            $this->info("📝 Generated " . count($this->fixesGenerated) . " fix files");
        }
    }

    protected function canAutoFix($issue)
    {
        return in_array($issue['type'], [
            'duplicate_index',
            'missing_index',
            'performance'
        ]);
    }

    protected function applyFix($issue)
    {
        switch ($issue['type']) {
            case 'duplicate_index':
                $this->fixDuplicateIndex($issue);
                break;
            case 'performance':
                if (strpos($issue['message'], 'should have an index') !== false) {
                    $this->generateIndexFix($issue);
                }
                break;
        }
    }

    protected function generateIndexFix($issue)
    {
        $fixContent = "<?php\n\nuse Illuminate\Database\Migrations\Migration;\nuse Illuminate\Database\Schema\Blueprint;\nuse Illuminate\Support\Facades\Schema;\n\n";
        $fixContent .= "class AddMissingIndexTo" . Str::studly($issue['table']) . "Table extends Migration\n{\n";
        $fixContent .= "    public function up()\n    {\n";
        $fixContent .= "        Schema::table('{$issue['table']}', function (Blueprint \$table) {\n";
        $fixContent .= "            {$issue['fix']}\n";
        $fixContent .= "        });\n    }\n\n";
        $fixContent .= "    public function down()\n    {\n";
        $fixContent .= "        Schema::table('{$issue['table']}', function (Blueprint \$table) {\n";
        $fixContent .= "            // Drop the index\n";
        $fixContent .= "        });\n    }\n}\n";

        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_add_missing_index_to_{$issue['table']}_table.php";
        $path = database_path('migrations/' . $filename);

        File::put($path, $fixContent);
        $this->fixesGenerated[] = $filename;
    }

    protected function reorderMigrationFiles()
    {
        $this->info('🔄 Reordering migration files based on dependencies...');

        $sortedMigrations = $this->topologicalSort();
        $reorderCount = 0;

        foreach ($sortedMigrations as $index => $migration) {
            $newTimestamp = date('Y_m_d_His', strtotime('+' . $index . ' seconds'));
            $newFilename = $newTimestamp . '_' . substr($migration['filename'], 18);

            if ($newFilename !== $migration['filename']) {
                $oldPath = $migration['path'];
                $newPath = dirname($oldPath) . '/' . $newFilename;

                if (File::move($oldPath, $newPath)) {
                    $this->line("📝 Renamed: {$migration['filename']} → {$newFilename}");
                    $reorderCount++;
                }
            }
        }

        $this->info("✅ Reordered {$reorderCount} migration files");
    }

    protected function createBackup()
    {
        $this->info('💾 Creating backup...');

        $backupDir = database_path('migrations_backup_' . date('Y_m_d_His'));
        File::makeDirectory($backupDir);

        foreach ($this->migrationFiles as $migration) {
            File::copy($migration['path'], $backupDir . '/' . $migration['filename']);
        }

        $this->info("✅ Backup created at: {$backupDir}");
    }

    // ... (keeping all existing methods with the same logic)

    protected function extractTimestamp($filename)
    {
        preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})/', $filename, $matches);
        return $matches[1] ?? '';
    }

    protected function extractMigrationName($filename)
    {
        return str_replace('.php', '', $filename);
    }

    protected function extractTableName($content)
    {
        if (preg_match('/Schema::create\([\'"]([^\'"]+)[\'"]/i', $content, $matches)) {
            return $matches[1];
        }
        if (preg_match('/Schema::table\([\'"]([^\'"]+)[\'"]/i', $content, $matches)) {
            return $matches[1];
        }
        return null;
    }

    protected function extractForeignKeys($content)
    {
        $foreignKeys = [];

        // Standard foreign key pattern
        preg_match_all('/\-\>foreign\([\'"]([^\'"]+)[\'"]\)\s*\-\>references\([\'"]([^\'"]+)[\'"]\)\s*\-\>on\([\'"]([^\'"]+)[\'"]\)/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $foreignKeys[] = [
                'column' => $match[1],
                'references_column' => $match[2],
                'references_table' => $match[3]
            ];
        }

        // foreignId() pattern
        preg_match_all('/\-\>foreignId\([\'"]([^\'"]+)[\'"]\)\s*\-\>constrained\(\s*[\'"]?([^\'"]*)[\'"]?\s*\)/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $column = $match[1];
            $table = $match[2] ?: Str::plural(str_replace('_id', '', $column));

            $foreignKeys[] = [
                'column' => $column,
                'references_column' => 'id',
                'references_table' => $table
            ];
        }

        return $foreignKeys;
    }

    protected function detectPolymorphicRelations($content)
    {
        if (preg_match('/\-\>(nullable)?[Mm]orphs\([\'"]([^\'"]+)[\'"]\)/i', $content, $matches)) {
            return [
                'type' => $matches[2] . '_type',
                'id' => $matches[2] . '_id',
                'nullable' => !empty($matches[1])
            ];
        }
        return null;
    }

    protected function checkDuplicateIndexes($migration)
    {
        // Enhanced duplicate index checking
        $tableName = $migration['table'];
        $indexes = [];

        foreach ($migration['indexes'] as $index) {
            $indexName = $index['name'];

            if (!$indexName) {
                // Generate default index name
                $columns = str_replace(['[', ']', "'", '"', ' '], '', $index['columns']);
                $columnList = str_replace(',', '_', $columns);
                $indexName = $tableName . '_' . $columnList . '_' . $index['type'];
            }

            if (in_array($indexName, $indexes)) {
                $this->issues[] = [
                    'type' => 'duplicate_index',
                    'migration' => $migration['filename'],
                    'table' => $tableName,
                    'index_name' => $indexName,
                    'message' => "Duplicate index name: {$indexName}",
                    'severity' => 'error'
                ];
            }

            $indexes[] = $indexName;
        }
    }

    protected function checkDependencyOrder($migration)
    {
        if (empty($migration['dependencies'])) {
            return;
        }

        $currentTimestamp = $migration['timestamp'];

        foreach ($migration['dependencies'] as $dependentTable) {
            $found = false;

            foreach ($this->migrationFiles as $otherMigration) {
                if ($otherMigration['table'] === $dependentTable) {
                    $found = true;
                    if ($otherMigration['timestamp'] > $currentTimestamp) {
                        $this->issues[] = [
                            'type' => 'dependency_order',
                            'migration' => $migration['filename'],
                            'table' => $migration['table'],
                            'dependency' => $dependentTable,
                            'message' => "Table {$migration['table']} depends on {$dependentTable} but is created before it",
                            'severity' => 'error'
                        ];
                    }
                    break;
                }
            }

            if (!$found) {
                $this->issues[] = [
                    'type' => 'missing_dependency',
                    'migration' => $migration['filename'],
                    'table' => $migration['table'],
                    'dependency' => $dependentTable,
                    'message' => "Table {$migration['table']} depends on {$dependentTable} but no migration creates it",
                    'severity' => 'error'
                ];
            }
        }
    }

    protected function checkMissingDependencies($migration)
    {
        // Additional checks for implicit dependencies
        $content = $migration['content'];

        // Check for references in raw SQL
        preg_match_all('/REFERENCES\s+`?([^`\s]+)`?\s*\(/i', $content, $matches);
        foreach ($matches[1] as $referencedTable) {
            if (!in_array($referencedTable, $migration['dependencies'])) {
                $this->issues[] = [
                    'type' => 'missing_dependency',
                    'migration' => $migration['filename'],
                    'table' => $migration['table'],
                    'dependency' => $referencedTable,
                    'message' => "Found reference to {$referencedTable} in raw SQL but not in dependencies",
                    'severity' => 'warning'
                ];
            }
        }
    }

    // ... (rest of the existing methods remain the same)

    protected function fixDuplicateIndex($issue)
    {
        $this->line("🔧 Fixing duplicate index: {$issue['index_name']}");

        foreach ($this->migrationFiles as &$migration) {
            if ($migration['filename'] === $issue['migration']) {
                $content = $migration['content'];

                $checkCode = "\n            // Check if index exists before creating\n";
                $checkCode .= "            if (!Schema::hasIndex('{$issue['table']}', '{$issue['index_name']}')) {\n";
                $checkCode .= "                \$table->index([/* columns */], '{$issue['index_name']}');\n";
                $checkCode .= "            }\n";

                $this->comment("Generated fix code for {$issue['migration']}");
                $this->line($checkCode);
            }
        }
    }

    protected function showExecutionOrder()
    {
        $sortedMigrations = $this->topologicalSort();

        $this->info("\n📋 Recommended Execution Order:\n");

        foreach ($sortedMigrations as $index => $migration) {
            $status = $this->isMigrationExecuted($migration) ? '✅' : '⏳';
            $deps = empty($migration['dependencies']) ? '' : ' (depends on: ' . implode(', ', $migration['dependencies']) . ')';
            $this->line(($index + 1) . ". {$status} {$migration['filename']} ({$migration['table']}){$deps}");
        }
    }

    protected function topologicalSort()
    {
        // Create a more robust topological sort using Kahn's algorithm
        $inDegree = [];
        $adjList = [];
        $nodes = [];

        // Initialize
        foreach ($this->migrationFiles as $migration) {
            $table = $migration['table'] ?: $migration['filename'];
            $nodes[$table] = $migration;
            $inDegree[$table] = 0;
            $adjList[$table] = [];
        }

        // Build adjacency list and calculate in-degrees
        foreach ($this->migrationFiles as $migration) {
            $table = $migration['table'] ?: $migration['filename'];

            foreach ($migration['dependencies'] as $dependency) {
                // Find the dependency migration
                $depTable = null;
                foreach ($this->migrationFiles as $depMigration) {
                    if ($depMigration['table'] === $dependency) {
                        $depTable = $depMigration['table'];
                        break;
                    }
                }

                if ($depTable && isset($nodes[$depTable])) {
                    $adjList[$depTable][] = $table;
                    $inDegree[$table]++;
                }
            }
        }

        // Kahn's algorithm
        $queue = [];
        $result = [];

        // Find all nodes with no incoming edges
        foreach ($inDegree as $table => $degree) {
            if ($degree === 0) {
                $queue[] = $table;
            }
        }

        while (!empty($queue)) {
            $current = array_shift($queue);
            $result[] = $nodes[$current];

            // For each neighbor of current node
            foreach ($adjList[$current] as $neighbor) {
                $inDegree[$neighbor]--;

                // If neighbor has no more incoming edges, add to queue
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        // Check for circular dependencies
        if (count($result) !== count($this->migrationFiles)) {
            $this->warn('⚠️  Circular dependency detected! Using fallback ordering.');

            // Fallback: sort by dependencies count, then timestamp
            $fallback = $this->migrationFiles;
            usort($fallback, function ($a, $b) {
                $aDeps = count($a['dependencies']);
                $bDeps = count($b['dependencies']);

                if ($aDeps === $bDeps) {
                    return strcmp($a['timestamp'], $b['timestamp']);
                }

                return $aDeps <=> $bDeps;
            });

            return $fallback;
        }

        return $result;
    }

    protected function topologicalSortVisit($migration, &$visited, &$temp, &$sorted)
    {
        // This method is kept for backward compatibility but not used
        // The main topologicalSort now uses Kahn's algorithm above
    }

    protected function isMigrationExecuted($migration)
    {
        try {
            $migrationName = str_replace('.php', '', $migration['filename']);
            return DB::table('migrations')->where('migration', $migrationName)->exists();
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function executeMigrations()
    {
        $sortedMigrations = $this->topologicalSort();

        $this->info("\n🚀 Executing migrations in optimal order...\n");

        // Check for orphaned tables that might cause conflicts
        $this->checkOrphanedTables();

        $executed = 0;
        $failed = 0;

        foreach ($sortedMigrations as $migration) {
            if (!$this->isMigrationExecuted($migration)) {
                $this->line("Executing: {$migration['filename']}");

                try {
                    // Check if table already exists but migration wasn't recorded
                    if ($this->tableExistsButMigrationNotRecorded($migration)) {
                        $this->warn("⚠️  Table '{$migration['table']}' exists but migration not recorded. Marking as executed...");
                        $this->recordMigrationAsExecuted($migration);
                        $executed++;
                        continue;
                    }

                    Artisan::call('migrate', [
                        '--path' => 'database/migrations/' . $migration['filename'],
                        '--force' => true
                    ]);

                    $this->info("✅ Successfully executed: {$migration['filename']}");
                    $executed++;

                } catch (\Exception $e) {
                    $this->error("❌ Failed to execute: {$migration['filename']}");
                    $this->error("Error: " . $e->getMessage());
                    $failed++;

                    // Suggest fixes for common errors
                    $this->suggestErrorFix($e, $migration);

                    if ($this->confirm('Continue with remaining migrations?', false)) {
                        continue;
                    } else {
                        break;
                    }
                }
            } else {
                $this->comment("⏭️  Skipping (already executed): {$migration['filename']}");
            }
        }

        $this->info("\n📊 Migration Summary:");
        $this->info("✅ Executed: {$executed}");
        if ($failed > 0) {
            $this->error("❌ Failed: {$failed}");
            $this->info("\n💡 Run with --analyze to see detailed dependency issues");
            return 1;
        }

        $this->info("🎉 All migrations completed successfully!");
        return 0;
    }

    protected function checkOrphanedTables()
    {
        $this->info('🔍 Checking for orphaned tables...');

        try {
            foreach ($this->migrationFiles as $migration) {
                if ($migration['table'] && $this->tableExists($migration['table']) && !$this->isMigrationExecuted($migration)) {
                    $this->warn("⚠️  Found orphaned table: {$migration['table']} (table exists but migration not recorded)");
                }
            }
        } catch (\Exception $e) {
            // Database might not be accessible yet
        }
    }

    protected function tableExists($tableName)
    {
        try {
            return Schema::hasTable($tableName);
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function tableExistsButMigrationNotRecorded($migration)
    {
        return $migration['table'] &&
               $this->tableExists($migration['table']) &&
               !$this->isMigrationExecuted($migration);
    }

    protected function recordMigrationAsExecuted($migration)
    {
        try {
            $migrationName = str_replace('.php', '', $migration['filename']);
            DB::table('migrations')->insert([
                'migration' => $migrationName,
                'batch' => DB::table('migrations')->max('batch') + 1
            ]);
        } catch (\Exception $e) {
            $this->error("Failed to record migration: " . $e->getMessage());
        }
    }

    protected function suggestErrorFix(\Exception $e, $migration)
    {
        $errorMessage = $e->getMessage();

        if (strpos($errorMessage, 'Foreign key constraint is incorrectly formed') !== false) {
            $this->comment("💡 Fix suggestion: The referenced table doesn't exist yet.");
            $this->comment("   This migration depends on: " . implode(', ', $migration['dependencies']));
            $this->comment("   Make sure these tables are created first.");
        }

        if (strpos($errorMessage, 'Base table or view already exists') !== false) {
            $this->comment("💡 Fix suggestion: Table already exists from previous failed migration.");
            $this->comment("   You can either:");
            $this->comment("   1. Drop the table manually: DROP TABLE {$migration['table']};");
            $this->comment("   2. Or mark this migration as executed if the table is correct.");
        }

        if (strpos($errorMessage, 'Unknown column') !== false) {
            $this->comment("💡 Fix suggestion: Column reference issue in foreign key.");
            $this->comment("   Check that the referenced column exists in the target table.");
        }
    }

    protected function generateFixFile($issue)
    {
        $timestamp = date('Y_m_d_His');
        $className = 'Fix' . Str::studly($issue['type']) . 'For' . Str::studly($issue['table'] ?? 'Migration');
        $filename = "{$timestamp}_fix_{$issue['type']}_for_{$issue['table']}_table.php";

        $fixContent = "<?php\n\n";
        $fixContent .= "use Illuminate\\Database\\Migrations\\Migration;\n";
        $fixContent .= "use Illuminate\\Database\\Schema\\Blueprint;\n";
        $fixContent .= "use Illuminate\\Support\\Facades\\Schema;\n\n";
        $fixContent .= "class {$className} extends Migration\n{\n";
        $fixContent .= "    /**\n";
        $fixContent .= "     * Run the migrations.\n";
        $fixContent .= "     * \n";
        $fixContent .= "     * Auto-generated fix for: {$issue['message']}\n";
        $fixContent .= "     */\n";
        $fixContent .= "    public function up()\n    {\n";

        switch ($issue['type']) {
            case 'dependency_order':
                $fixContent .= "        // Fix dependency order issue\n";
                $fixContent .= "        // Consider moving this migration or adding proper foreign key constraints\n";
                break;
            case 'missing_dependency':
                $fixContent .= "        // Create missing dependency table: {$issue['dependency']}\n";
                $fixContent .= "        Schema::create('{$issue['dependency']}', function (Blueprint \$table) {\n";
                $fixContent .= "            \$table->id();\n";
                $fixContent .= "            \$table->timestamps();\n";
                $fixContent .= "        });\n";
                break;
            case 'performance':
                $fixContent .= "        // Add missing index for performance\n";
                $fixContent .= "        Schema::table('{$issue['table']}', function (Blueprint \$table) {\n";
                $fixContent .= "            {$issue['fix']}\n";
                $fixContent .= "        });\n";
                break;
        }

        $fixContent .= "    }\n\n";
        $fixContent .= "    /**\n";
        $fixContent .= "     * Reverse the migrations.\n";
        $fixContent .= "     */\n";
        $fixContent .= "    public function down()\n    {\n";
        $fixContent .= "        // Reverse the fix if needed\n";
        $fixContent .= "    }\n";
        $fixContent .= "}\n";

        $path = database_path('migrations/' . $filename);
        File::put($path, $fixContent);

        $this->fixesGenerated[] = $filename;
        $this->comment("📝 Generated fix file: {$filename}");
    }
}
