<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MigrationController extends Controller
{
    public function parseMigrations(Request $request)
    {
        try {
            $migrationText = $request->input('migration_text');
            $tables = $this->parseMigrationText($migrationText);
            
            return response()->json([
                'success' => true,
                'tables' => $tables
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function executeQuery(Request $request)
    {
        try {
            $query = $request->input('query');
            $tableData = $request->input('table_data', []);
            
            // Symulacja wykonania zapytania Eloquent
            $result = $this->simulateEloquentQuery($query, $tableData);
            
            return response()->json([
                'success' => true,
                'result' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function saveTableData(Request $request)
    {
        try {
            $table = $request->input('table');
            $data = $request->input('data');
            $tableStructure = $request->input('table_structure');
            
            // Automatyczne wypełnianie danych
            $processedData = $this->autoFillData($data, $tableStructure);
            
            return response()->json([
                'success' => true,
                'message' => "Dane zostały zapisane do tabeli {$table}",
                'processed_data' => $processedData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    private function autoFillData($data, $tableStructure)
    {
        $processedData = [];
        $nextId = 1;
        $errors = [];
        
        // Znajdź największe ID
        foreach ($data as $row) {
            if (isset($row['id']) && is_numeric($row['id'])) {
                $nextId = max($nextId, intval($row['id']) + 1);
            }
        }
        
        foreach ($data as $index => $row) {
            $processedRow = $row;
            $rowErrors = [];
            
            foreach ($tableStructure as $column) {
                $columnName = $column['name'];
                $value = $row[$columnName] ?? '';
                
                // Auto-increment ID
                if ($column['name'] === 'id' && isset($column['autoIncrement']) && empty($value)) {
                    $processedRow[$columnName] = $nextId++;
                    continue;
                }
                
                // Wypełnij timestamps automatycznie
                if (isset($column['autoFill']) && $column['autoFill'] && empty($value)) {
                    $processedRow[$columnName] = date('Y-m-d H:i:s');
                    continue;
                }
                
                // Wypełnij wartości domyślne
                if (empty($value) && isset($column['default']) && $column['default'] !== null) {
                    $processedRow[$columnName] = $column['default'];
                    continue;
                }
                
                // Walidacja
                if (empty($value) && !isset($column['nullable']) && !isset($column['default'])) {
                    if ($column['name'] !== 'id' || !isset($column['autoIncrement'])) {
                        $rowErrors[] = "Pole '{$columnName}' nie może być puste";
                    }
                }
                
                // Walidacja długości string
                if ($column['type'] === 'string' && isset($column['maxLength']) && !empty($value)) {
                    if (strlen($value) > $column['maxLength']) {
                        $rowErrors[] = "Pole '{$columnName}' przekracza maksymalną długość {$column['maxLength']} znaków";
                    }
                }
                
                // Walidacja unique
                if (isset($column['unique']) && $column['unique'] && !empty($value)) {
                    foreach ($processedData as $existingRow) {
                        if (isset($existingRow[$columnName]) && $existingRow[$columnName] === $value) {
                            $rowErrors[] = "Wartość '{$value}' w polu '{$columnName}' musi być unikalna";
                            break;
                        }
                    }
                }
            }
            
            if (!empty($rowErrors)) {
                $errors["row_$index"] = $rowErrors;
            }
            
            $processedData[] = $processedRow;
        }
        
        if (!empty($errors)) {
            throw new \Exception('Błędy walidacji: ' . json_encode($errors));
        }
        
        return $processedData;
    }

    private function parseMigrationText($migrationText)
    {
        $tables = [];
        
        // Wzorzec dla Laravel Schema::create
        $pattern = '/Schema::create\s*\(\s*[\'"](\w+)[\'"]\s*,\s*function\s*\(\s*Blueprint\s*\$table\s*\)\s*\{(.*?)\}\s*\)\s*;/s';
        
        if (preg_match_all($pattern, $migrationText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tableName = $match[1];
                $tableDefinition = $match[2];
                
                $columns = $this->parseTableDefinition($tableDefinition);
                $tables[$tableName] = $columns;
            }
        }
        
        // Fallback dla prostego SQL CREATE TABLE
        if (empty($tables)) {
            $sqlPattern = '/CREATE\s+TABLE\s+(\w+)\s*\((.*?)\);/si';
            if (preg_match_all($sqlPattern, $migrationText, $sqlMatches, PREG_SET_ORDER)) {
                foreach ($sqlMatches as $match) {
                    $tableName = $match[1];
                    $columnsText = $match[2];
                    
                    $columns = $this->parseSqlColumns($columnsText);
                    $tables[$tableName] = $columns;
                }
            }
        }
        
        return $tables;
    }

    private function parseTableDefinition($tableDefinition)
    {
        $columns = [];
        
        // Podziel definicję na linie dla łatwiejszego parsowania
        $lines = explode("\n", $tableDefinition);
        
        // Wzorce dla różnych typów kolumn Laravel
        $patterns = [
            '/\$table->id\(\s*[\'"]?(\w*)[\'"]?\s*\)/' => ['name' => 'id', 'type' => 'bigint', 'autoIncrement' => true],
            '/\$table->string\(\s*[\'"](\w+)[\'"](?:\s*,\s*(\d+))?\s*\)(?:->(\w+)\([^)]*\))*/' => ['type' => 'string'],
            '/\$table->text\(\s*[\'"](\w+)[\'"]\s*\)/' => ['type' => 'text'],
            '/\$table->integer\(\s*[\'"](\w+)[\'"]\s*\)/' => ['type' => 'integer'],
            '/\$table->bigInteger\(\s*[\'"](\w+)[\'"]\s*\)/' => ['type' => 'bigint'],
            '/\$table->boolean\(\s*[\'"](\w+)[\'"]\s*\)/' => ['type' => 'boolean'],
            '/\$table->decimal\(\s*[\'"](\w+)[\'"](?:\s*,\s*(\d+)\s*,\s*(\d+))?\s*\)/' => ['type' => 'decimal'],
            '/\$table->timestamp\(\s*[\'"](\w+)[\'"]\s*\)/' => ['type' => 'timestamp'],
            '/\$table->timestamps\(\s*\)/' => ['special' => 'timestamps'],
            '/\$table->softDeletes\(\s*\)/' => ['special' => 'softDeletes'],
            '/\$table->foreignId\(\s*[\'"](\w+)[\'"]\s*\)/' => ['type' => 'foreignId'],
        ];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            foreach ($patterns as $pattern => $config) {
                if (preg_match($pattern, $line, $match)) {
                    if (isset($config['special'])) {
                        if ($config['special'] === 'timestamps') {
                            $columns[] = ['name' => 'created_at', 'type' => 'timestamp', 'autoFill' => true];
                            $columns[] = ['name' => 'updated_at', 'type' => 'timestamp', 'autoFill' => true];
                        } elseif ($config['special'] === 'softDeletes') {
                            $columns[] = ['name' => 'deleted_at', 'type' => 'timestamp', 'nullable' => true];
                        }
                    } elseif (isset($config['name'])) {
                        $columns[] = array_merge(['name' => $config['name']], $config);
                    } else {
                        $column = ['name' => $match[1], 'type' => $config['type']];
                        
                        // Dla string - sprawdź długość
                        if ($config['type'] === 'string' && isset($match[2]) && !empty($match[2])) {
                            $column['maxLength'] = (int)$match[2];
                        }
                        
                        // Dla decimal - sprawdź precision i scale
                        if ($config['type'] === 'decimal') {
                            if (isset($match[2]) && !empty($match[2])) {
                                $column['precision'] = (int)$match[2];
                            }
                            if (isset($match[3]) && !empty($match[3])) {
                                $column['scale'] = (int)$match[3];
                            }
                        }
                        
                        // Sprawdź modyfikatory w tej linii
                        if (preg_match('/->default\(([^)]+)\)/', $line, $defaultMatch)) {
                            $defaultValue = trim($defaultMatch[1], '"\'');
                            // Konwertuj wartości boolean i integer
                            if ($defaultValue === 'true') {
                                $column['default'] = true;
                            } elseif ($defaultValue === 'false') {
                                $column['default'] = false;
                            } elseif (is_numeric($defaultValue)) {
                                $column['default'] = (int)$defaultValue;
                            } else {
                                $column['default'] = $defaultValue;
                            }
                        }
                        
                        if (strpos($line, '->unique()') !== false) {
                            $column['unique'] = true;
                        }
                        if (strpos($line, '->nullable()') !== false) {
                            $column['nullable'] = true;
                        }
                        
                        $columns[] = $column;
                    }
                    break; // Przerwij sprawdzanie innych wzorców dla tej linii
                }
            }
        }

        return $columns;
    }

    private function parseSqlColumns($columnsText)
    {
        $columns = [];
        $columnLines = explode(',', $columnsText);
        
        foreach ($columnLines as $line) {
            $line = trim($line);
            if ($line && !stripos($line, 'FOREIGN KEY') && !stripos($line, 'CONSTRAINT')) {
                if (preg_match('/(\w+)\s+(\w+)/', $line, $match)) {
                    $columns[] = [
                        'name' => $match[1],
                        'type' => strtolower($match[2])
                    ];
                }
            }
        }
        
        return $columns;
    }

    private function simulateEloquentQuery($query, $tableData)
    {
        $query = trim($query);
        
        // Normalizuj separatory dziesiętne (zamień przecinek na kropkę)
        $query = preg_replace('/(\d+),(\d+)/', '$1.$2', $query);
        
        // Sprawdź czy to zapytanie create - jeśli tak, dodaj do danych
        if (preg_match('/(\w+)::create\(\s*\[(.*?)\]\s*\)/s', $query, $matches)) {
            $model = $matches[1];
            $tableName = $this->getTableNameFromModel($model);
            $createData = $this->parseCreateData($matches[2]);
            
            // Dodaj timestamps jeśli nie są ustawione
            if (!isset($createData['created_at'])) {
                $createData['created_at'] = date('Y-m-d H:i:s');
            }
            if (!isset($createData['updated_at'])) {
                $createData['updated_at'] = date('Y-m-d H:i:s');
            }
            
            // Generuj ID jeśli nie jest ustawione
            if (!isset($createData['id'])) {
                $maxId = 0;
                if (isset($tableData[$tableName])) {
                    foreach ($tableData[$tableName] as $row) {
                        if (isset($row['id']) && is_numeric($row['id'])) {
                            $maxId = max($maxId, intval($row['id']));
                        }
                    }
                }
                $createData['id'] = $maxId + 1;
            }
            
            return [
                'action' => 'create',
                'message' => 'Rekord został utworzony',
                'data' => $createData,
                'table' => $tableName
            ];
        }
        
        // Zapytania select
        if (preg_match('/(\w+)::all\(\)/', $query, $matches)) {
            $model = $matches[1];
            $tableName = $this->getTableNameFromModel($model);
            return $tableData[$tableName] ?? [];
        }
        
        // Obsługa zamknięć (closures) w with()
        if (preg_match('/(\w+)::with\(\s*\[([^]]+)\]\s*\)->get\(\)/', $query, $matches)) {
            $model = $matches[1];
            $withContent = $matches[2];
            
            return $this->handleClosureQuery($model, $withContent, $tableData);
        }
        
        // Wielokrotne where z with - kompleksowe zapytania
        if (preg_match('/(\w+)::where\([^)]+\)(?:->where\([^)]+\))*->with\([^)]+\)->get\(\)/', $query)) {
            return $this->handleMultipleWhereWithQuery($query, $tableData);
        }
        
        // Wielokrotne where bez with
        if (preg_match('/(\w+)::where\([^)]+\)(?:->where\([^)]+\))+->get\(\)/', $query)) {
            return $this->handleMultipleWhereQuery($query, $tableData);
        }
        
        // Pojedyncze where z wieloma argumentami i with
        if (preg_match('/(\w+)::where\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"]?([^,\)]+)[\'"]?\s*,\s*[\'"]?([^)]+)[\'"]?\s*\)->with\([^)]+\)->get\(\)/', $query, $matches)) {
            $model = $matches[1];
            $column = $matches[2];
            $operator = trim($matches[3], '\'"');
            $value = trim($matches[4], '\'"');
            
            // Parsuj relacje
            if (preg_match('/->with\(\s*([^)]+)\s*\)/', $query, $withMatches)) {
                $relationsString = $withMatches[1];
                return $this->executeWhereWithQuery($model, $column, $operator, $value, $relationsString, $tableData);
            }
        }
        
        // Pojedyncze where z wieloma argumentami bez with
        if (preg_match('/(\w+)::where\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"]?([^,\)]+)[\'"]?\s*,\s*[\'"]?([^)]+)[\'"]?\s*\)->get\(\)/', $query, $matches)) {
            $model = $matches[1];
            $tableName = $this->getTableNameFromModel($model);
            $column = $matches[2];
            $operator = trim($matches[3], '\'"');
            $value = trim($matches[4], '\'"');
            
            return $this->filterDataByCondition($tableData[$tableName] ?? [], $column, $operator, $value);
        }
        
        // Znajdowanie po ID
        if (preg_match('/(\w+)::find\((\d+)\)/', $query, $matches)) {
            $model = $matches[1];
            $id = $matches[2];
            $tableName = $this->getTableNameFromModel($model);
            $data = $tableData[$tableName] ?? [];
            
            foreach ($data as $row) {
                if (isset($row['id']) && (string)$row['id'] === (string)$id) {
                    return $row;
                }
            }
            return null;
        }
        
        // Zapytania where + with - format 2 parametrów: where('column', 'value')
        if (preg_match('/(\w+)::where\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"]([^\'\"]+)[\'"]\s*\)->with\(\s*(.+?)\s*\)->get\(\)/', $query, $matches)) {
            $model = $matches[1];
            $column = $matches[2];
            $operator = '=';
            $value = $matches[3];
            $relationsString = $matches[4];
            
            return $this->executeWhereWithQuery($model, $column, $operator, $value, $relationsString, $tableData);
        }
        
        // Zapytania where + with - format bez cudzysłowów wokół wartości (np. liczby, boolean)
        if (preg_match('/(\w+)::where\(\s*[\'"](\w+)[\'"]\s*,\s*([^,)]+)\s*\)->with\(\s*(.+?)\s*\)->get\(\)/', $query, $matches)) {
            $model = $matches[1];
            $column = $matches[2];
            $operator = '=';
            $value = trim($matches[3]);
            $relationsString = $matches[4];
            
            return $this->executeWhereWithQuery($model, $column, $operator, $value, $relationsString, $tableData);
        }
        
        // Zapytania where bez with - format 2 parametrów
        if (preg_match('/(\w+)::where\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"]([^\'\"]+)[\'"]\s*\)->get\(\)/', $query, $matches)) {
            $model = $matches[1];
            $tableName = $this->getTableNameFromModel($model);
            $column = $matches[2];
            $value = $matches[3];
            
            return $this->filterDataByCondition($tableData[$tableName] ?? [], $column, '=', $value);
        }
        
        // Zapytania z relacjami - obsługa wielu relacji i zagnieżdżonych
        if (preg_match('/(\w+)::with\(\s*(.+?)\s*\)->get\(\)/', $query, $matches)) {
            $model = $matches[1];
            $relationsString = $matches[2];
            $tableName = $this->getTableNameFromModel($model);
            
            // Parse relations - obsługa różnych formatów
            $relations = [];
            
            // Format 1: 'relation1','relation2' lub "relation1","relation2"
            if (preg_match_all('/[\'"]([^\'\"]+)[\'"]/', $relationsString, $relationMatches)) {
                $relations = $relationMatches[1];
            }
            // Format 2: ['relation1','relation2'] lub ["relation1","relation2"]
            elseif (preg_match('/\[(.+)\]/', $relationsString, $arrayMatch)) {
                if (preg_match_all('/[\'"]([^\'\"]+)[\'"]/', $arrayMatch[1], $relationMatches)) {
                    $relations = $relationMatches[1];
                }
            }
            
            $availableTables = array_keys($tableData);
            $mainData = $tableData[$tableName] ?? [];
            
            $result = [];
            foreach ($mainData as $row) {
                $rowWithRelations = $row;
                
                // Grupuj relacje według pierwszego poziomu
                $groupedRelations = $this->groupRelationsByFirstLevel($relations);
                
                // Przetwórz każdą grupę relacji
                foreach ($groupedRelations as $firstLevel => $nestedRelations) {
                    $rowWithRelations = $this->processGroupedRelations($rowWithRelations, $firstLevel, $nestedRelations, $tableName, $tableData, $availableTables);
                }
                
                $result[] = $rowWithRelations;
            }
            
            return $result;
        }
        
        if (preg_match('/(\w+)::.*->update\(/', $query, $matches)) {
            return ['message' => 'Rekord został zaktualizowany'];
        }
        
        if (preg_match('/(\w+)::.*->delete\(\)/', $query, $matches)) {
            return ['message' => 'Rekord został usunięty'];
        }
        
        // Żaden regex nie pasuje
        return ['message' => 'Zapytanie zostało wykonane', 'query' => $query];
    }
    
    private function handleClosureQuery($model, $withContent, $tableData)
    {
        $tableName = $this->getTableNameFromModel($model);
        $mainData = $tableData[$tableName] ?? [];
        
        // Parsuj zawartość with() - szukaj closure patterns
        $relations = [];
        
        // Pattern dla closures: 'products' => function($query) { ... }
        if (preg_match_all('/[\'"](\w+)[\'"]\s*=>\s*function\s*\(\s*\$query\s*\)\s*\{\s*([^}]+)\s*\}/', $withContent, $closureMatches, PREG_SET_ORDER)) {
            foreach ($closureMatches as $closure) {
                $relationName = $closure[1];
                $closureBody = $closure[2];
                
                // Parsuj warunki w closure
                $conditions = $this->parseClosureConditions($closureBody);
                $relations[$relationName] = $conditions;
            }
        }        // Parsuj zwykłe relacje: 'relation1', 'relation2', 'relation1.nested'
        // Ale nie łap argumentów z function() { ... }
        $cleanWithContent = preg_replace('/function\s*\([^)]*\)\s*\{[^}]*\}/', '', $withContent);
        if (preg_match_all('/[\'"]([a-zA-Z_][a-zA-Z0-9_.]*)[\'"](?!\s*=>)/', $cleanWithContent, $simpleMatches)) {
            foreach ($simpleMatches[1] as $relation) {
                if (!isset($relations[$relation])) {
                    $relations[$relation] = [];
                }
            }
        }
          $result = [];
        foreach ($mainData as $row) {
            $rowWithRelations = $row;
            $hasMatchingRelations = true;
            
            foreach ($relations as $relationName => $conditions) {
                $rowWithRelations = $this->addRelationWithConditions($rowWithRelations, $relationName, $conditions, $tableName, $tableData);
                
                // Jeśli relacja ma warunki (closure) i jest pusta, oznacza to że rekord główny nie powinien być zwrócony
                if (!empty($conditions)) {
                    if (!isset($rowWithRelations[$relationName]) || 
                        (is_array($rowWithRelations[$relationName]) && empty($rowWithRelations[$relationName]))) {
                        $hasMatchingRelations = false;
                        break;
                    }
                }
            }
            
            // Dodaj rekord tylko jeśli ma pasujące relacje lub nie ma warunków w closure
            if ($hasMatchingRelations) {
                $result[] = $rowWithRelations;
            }
        }
        
        return $result;
    }
      private function parseClosureConditions($closureBody)
    {
        $conditions = [];
        
        // Podziel closure body na części - usuń \$query-> i podziel po ->where
        $cleanedBody = str_replace('$query->', '', $closureBody);
        $whereParts = explode('->where', $cleanedBody);
        
        foreach ($whereParts as $part) {
            $part = trim($part);
            if (empty($part) || !str_contains($part, '(')) continue;
            
            // Znajdź argumenty w where() - obsłuż różne formaty
            if (preg_match('/\(\s*[\'"](\w+)[\'"]\s*,\s*([^)]+)\s*\)/', $part, $match)) {
                $column = $match[1];
                $rest = trim($match[2]);
                
                // Sprawdź czy to 2 lub 3 argumenty
                $args = $this->parseWhereArguments($rest);
                
                if (count($args) == 1) {
                    // where('column', 'value') - operator domyślny '='
                    $conditions[] = [
                        'column' => $column,
                        'operator' => '=',
                        'value' => trim($args[0], '\'"')
                    ];
                } elseif (count($args) == 2) {
                    // where('column', 'operator', 'value')
                    $conditions[] = [
                        'column' => $column,
                        'operator' => trim($args[0], '\'"'),
                        'value' => trim($args[1], '\'"')
                    ];
                }
            }
        }
        
        return $conditions;
    }
    
    private function parseWhereArguments($args)
    {
        $result = [];
        $parts = explode(',', $args);
        
        foreach ($parts as $part) {
            $result[] = trim($part);
        }
        
        return $result;
    }
      private function addRelationWithConditions($row, $relationName, $conditions, $currentTableName, $tableData)
    {
        // Sprawdź czy to zagnieżdżona relacja (np. 'products.brand')
        if (str_contains($relationName, '.')) {
            return $this->addNestedRelation($row, $relationName, $conditions, $currentTableName, $tableData);
        }
        
        $relationTable = $this->getTableNameFromModel(ucfirst($relationName));
        $relationData = $tableData[$relationTable] ?? [];
        $isHasMany = $this->isHasManyRelation($currentTableName, $relationName);
        
        if ($isHasMany) {
            // hasMany: szukaj w tabeli relacji po kluczu obcym
            $foreignKey = $this->getForeignKeyFromTableName($currentTableName);
            $relatedItems = [];
            
            foreach ($relationData as $relRow) {
                if (isset($relRow[$foreignKey]) && (string)$relRow[$foreignKey] === (string)$row['id']) {
                    // Sprawdź warunki closure
                    $meetsConditions = true;
                    foreach ($conditions as $condition) {
                        if (!$this->checkCondition($relRow, $condition)) {
                            $meetsConditions = false;
                            break;
                        }
                    }
                    
                    if ($meetsConditions) {
                        $relatedItems[] = $relRow;
                    }
                }
            }
            
            $row[$relationName] = $relatedItems;
        } else {
            // belongsTo: szukaj w tabeli relacji po ID
            $relationKey = rtrim($relationName, 's') . '_id';
            
            if (isset($row[$relationKey])) {
                foreach ($relationData as $relRow) {
                    if (isset($relRow['id']) && (string)$relRow['id'] === (string)$row[$relationKey]) {
                        // Sprawdź warunki closure (zwykle nie ma w belongsTo, ale na wszelki wypadek)
                        $meetsConditions = true;
                        foreach ($conditions as $condition) {
                            if (!$this->checkCondition($relRow, $condition)) {
                                $meetsConditions = false;
                                break;
                            }
                        }
                        
                        if ($meetsConditions) {
                            $row[$relationName] = $relRow;
                            break;
                        }
                    }
                }
            }
        }
        
        return $row;
    }
    
    private function addNestedRelation($row, $nestedRelationName, $conditions, $currentTableName, $tableData)
    {
        $parts = explode('.', $nestedRelationName);
        $baseRelation = $parts[0];
        $nestedRelation = $parts[1];
        
        // Najpierw załaduj podstawową relację jeśli nie jest załadowana
        if (!isset($row[$baseRelation])) {
            $row = $this->addRelationWithConditions($row, $baseRelation, $conditions, $currentTableName, $tableData);
        }
        
        // Teraz dodaj zagnieżdżone relacje do każdego elementu podstawowej relacji
        if (isset($row[$baseRelation])) {
            if (is_array($row[$baseRelation]) && isset($row[$baseRelation][0])) {
                // hasMany - array of items
                foreach ($row[$baseRelation] as &$item) {
                    $item = $this->addRelationWithConditions($item, $nestedRelation, [], $baseRelation, $tableData);
                }
            } elseif (is_array($row[$baseRelation]) && !empty($row[$baseRelation])) {
                // belongsTo - single item
                $row[$baseRelation] = $this->addRelationWithConditions($row[$baseRelation], $nestedRelation, [], $baseRelation, $tableData);
            }
        }
        
        return $row;
    }
    
    private function checkCondition($row, $condition)
    {
        $column = $condition['column'];
        $operator = $condition['operator'];
        $value = $condition['value'];
        
        if (!isset($row[$column])) {
            return false;
        }
        
        $rowValue = $row[$column];
        
        // Normalizuj boolean values
        $value = $this->normalizeValue($value);
        $rowValue = $this->normalizeValue($rowValue);
        
        switch ($operator) {
            case '=':
            case '==':
                return $this->compareValues($rowValue, $value);
            case '>':
                return is_numeric($rowValue) && is_numeric($value) && (float)$rowValue > (float)$value;
            case '<':
                return is_numeric($rowValue) && is_numeric($value) && (float)$rowValue < (float)$value;
            case '>=':
                return is_numeric($rowValue) && is_numeric($value) && (float)$rowValue >= (float)$value;
            case '<=':
                return is_numeric($rowValue) && is_numeric($value) && (float)$rowValue <= (float)$value;
            case 'like':
                $pattern = str_replace('%', '.*', preg_quote($value, '/'));
                return preg_match('/' . $pattern . '/i', $rowValue);
            default:
                return false;
        }
    }
    
    private function handleMultipleWhereWithQuery($query, $tableData)
    {
        // Parsuj model
        if (!preg_match('/^(\w+)::/', $query, $modelMatch)) {
            return ['message' => 'Nie można sparsować modelu', 'query' => $query];
        }
        
        $model = $modelMatch[1];
        $tableName = $this->getTableNameFromModel($model);
        $data = $tableData[$tableName] ?? [];
        
        // Parsuj wszystkie where()
        $conditions = [];
        if (preg_match_all('/where\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"]?([^,)]+)[\'"]?\s*(?:,\s*[\'"]?([^)]+)[\'"]?)?\s*\)/', $query, $whereMatches, PREG_SET_ORDER)) {
            foreach ($whereMatches as $where) {
                $column = $where[1];
                $operator = isset($where[3]) ? trim($where[2], '\'"') : '=';
                $value = isset($where[3]) ? trim($where[3], '\'"') : trim($where[2], '\'"');
                
                $conditions[] = [
                    'column' => $column,
                    'operator' => $operator,
                    'value' => $value
                ];
            }
        }
        
        // Filtruj dane według wszystkich warunków
        $filteredData = [];
        foreach ($data as $row) {
            $meetsAllConditions = true;
            foreach ($conditions as $condition) {
                if (!$this->checkCondition($row, $condition)) {
                    $meetsAllConditions = false;
                    break;
                }
            }
            
            if ($meetsAllConditions) {
                $filteredData[] = $row;
            }
        }
        
        // Parsuj relacje z with()
        if (preg_match('/with\(\s*([^)]+)\s*\)/', $query, $withMatch)) {
            $relationsString = $withMatch[1];
            $relations = [];
            
            if (preg_match_all('/[\'"]([^\'\"]+)[\'"]/', $relationsString, $relationMatches)) {
                $relations = $relationMatches[1];
            }
            
            $availableTables = array_keys($tableData);
            $result = [];
            
            foreach ($filteredData as $row) {
                $rowWithRelations = $row;
                
                // Grupuj relacje według pierwszego poziomu
                $groupedRelations = $this->groupRelationsByFirstLevel($relations);
                
                // Przetwórz każdą grupę relacji
                foreach ($groupedRelations as $firstLevel => $nestedRelations) {
                    $rowWithRelations = $this->processGroupedRelations($rowWithRelations, $firstLevel, $nestedRelations, $tableName, $tableData, $availableTables);
                }
                
                $result[] = $rowWithRelations;
            }
            
            return $result;
        }
        
        return $filteredData;
    }
    
    private function handleMultipleWhereQuery($query, $tableData)
    {
        // Parsuj model
        if (!preg_match('/^(\w+)::/', $query, $modelMatch)) {
            return ['message' => 'Nie można sparsować modelu', 'query' => $query];
        }
        
        $model = $modelMatch[1];
        $tableName = $this->getTableNameFromModel($model);
        $data = $tableData[$tableName] ?? [];
        
        // Parsuj wszystkie where()
        $conditions = [];
        if (preg_match_all('/where\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"]?([^,)]+)[\'"]?\s*(?:,\s*[\'"]?([^)]+)[\'"]?)?\s*\)/', $query, $whereMatches, PREG_SET_ORDER)) {
            foreach ($whereMatches as $where) {
                $column = $where[1];
                $operator = isset($where[3]) ? trim($where[2], '\'"') : '=';
                $value = isset($where[3]) ? trim($where[3], '\'"') : trim($where[2], '\'"');
                
                $conditions[] = [
                    'column' => $column,
                    'operator' => $operator,
                    'value' => $value
                ];
            }
        }
        
        // Filtruj dane według wszystkich warunków
        $filteredData = [];
        foreach ($data as $row) {
            $meetsAllConditions = true;
            foreach ($conditions as $condition) {
                if (!$this->checkCondition($row, $condition)) {
                    $meetsAllConditions = false;
                    break;
                }
            }
            
            if ($meetsAllConditions) {
                $filteredData[] = $row;
            }
        }
        
        return $filteredData;
    }
    
    private function filterDataByCondition($data, $column, $operator, $value)
    {
        $filtered = [];
        
        foreach ($data as $row) {
            if (isset($row[$column])) {
                $condition = [
                    'column' => $column,
                    'operator' => $operator,
                    'value' => $value
                ];
                
                if ($this->checkCondition($row, $condition)) {
                    $filtered[] = $row;
                }
            }
        }
        
        return $filtered;
    }
    
    private function executeWhereWithQuery($model, $column, $operator, $value, $relationsString, $tableData)
    {
        $tableName = $this->getTableNameFromModel($model);
        $data = $tableData[$tableName] ?? [];
        
        // Najpierw filtruj po where
        $filteredData = [];
        foreach ($data as $row) {
            if (isset($row[$column])) {
                $condition = [
                    'column' => $column,
                    'operator' => $operator,
                    'value' => $value
                ];
                
                if ($this->checkCondition($row, $condition)) {
                    $filteredData[] = $row;
                }
            }
        }
        
        // Parse relations
        $relations = [];
        if (preg_match_all('/[\'"]([^\'\"]+)[\'"]/', $relationsString, $relationMatches)) {
            $relations = $relationMatches[1];
        } elseif (preg_match('/\[(.+)\]/', $relationsString, $arrayMatch)) {
            if (preg_match_all('/[\'"]([^\'\"]+)[\'"]/', $arrayMatch[1], $relationMatches)) {
                $relations = $relationMatches[1];
            }
        }
        
        $availableTables = array_keys($tableData);
        $result = [];
        
        foreach ($filteredData as $row) {
            $rowWithRelations = $row;
            
            // Grupuj relacje według pierwszego poziomu
            $groupedRelations = $this->groupRelationsByFirstLevel($relations);
            
            // Przetwórz każdą grupę relacji
            foreach ($groupedRelations as $firstLevel => $nestedRelations) {
                $rowWithRelations = $this->processGroupedRelations($rowWithRelations, $firstLevel, $nestedRelations, $tableName, $tableData, $availableTables);
            }
            
            $result[] = $rowWithRelations;
        }
        
        return $result;
    }
    
    private function getTableNameFromModel($model)
    {
        // Normalizuj nazwę modelu (case insensitive)
        $modelNormalized = ucfirst(strtolower($model));
        
        // Mapowanie nazw modeli na nazwy tabel
        $modelToTableMap = [
            'Product' => 'products',
            'Brand' => 'brands',
            'Category' => 'categories',
            'Supplier' => 'suppliers',
            'User' => 'users',
            'Post' => 'posts'
        ];
        
        if (isset($modelToTableMap[$modelNormalized])) {
            return $modelToTableMap[$modelNormalized];
        }
        
        // Fallback - konwersja nazwy modelu na nazwę tabeli (Product -> products)
        $tableName = strtolower($modelNormalized);
        if (substr($tableName, -1) !== 's') {
            $tableName .= 's';
        }
        return $tableName;
    }
    
    private function groupRelationsByFirstLevel($relations)
    {
        $grouped = [];
        
        foreach ($relations as $relationPath) {
            if (strpos($relationPath, '.') !== false) {
                // Zagnieżdżona relacja: "products.brand" -> firstLevel: "products", nested: "brand"
                $parts = explode('.', $relationPath);
                $firstLevel = $parts[0];
                $nestedPath = implode('.', array_slice($parts, 1));
                
                if (!isset($grouped[$firstLevel])) {
                    $grouped[$firstLevel] = [];
                }
                $grouped[$firstLevel][] = $nestedPath;
            } else {
                // Pojedyncza relacja: "brand" -> firstLevel: "brand", nested: []
                if (!isset($grouped[$relationPath])) {
                    $grouped[$relationPath] = [];
                }
            }
        }
        
        return $grouped;
    }
    
    private function processGroupedRelations($row, $firstLevel, $nestedRelations, $mainTableName, $tableData, $availableTables)
    {
        // Najpierw dodaj relację pierwszego poziomu
        $row = $this->addSingleRelation($row, $firstLevel, $mainTableName, $tableData, $availableTables);
        
        // Następnie dodaj wszystkie zagnieżdżone relacje
        if (!empty($nestedRelations) && isset($row[$firstLevel]) && is_array($row[$firstLevel])) {
            foreach ($row[$firstLevel] as $key => $relatedItem) {
                $relatedTableName = $this->getTableNameFromModel(ucfirst($firstLevel));
                
                // Dodaj wszystkie zagnieżdżone relacje do tego elementu
                foreach ($nestedRelations as $nestedRelation) {
                    $row[$firstLevel][$key] = $this->addSingleRelation($row[$firstLevel][$key], $nestedRelation, $relatedTableName, $tableData, $availableTables);
                }
            }
        }
        
        return $row;
    }
    
    private function addSingleRelation($row, $relation, $currentTableName, $tableData, $availableTables)
    {
        $relationTable = $this->getTableNameFromModel(ucfirst($relation));
        
        // Jeśli nie ma dokładnej nazwy tabeli, spróbuj znaleźć podobną
        if (!in_array($relationTable, $availableTables)) {
            $relationLower = strtolower($relation);
            foreach ($availableTables as $table) {
                if (strpos($table, $relationLower) !== false || 
                    strpos($relationLower, str_replace('s', '', $table)) !== false) {
                    $relationTable = $table;
                    break;
                }
            }
        }
        
        $relationData = $tableData[$relationTable] ?? [];
        $isHasMany = $this->isHasManyRelation($currentTableName, $relation);
        
        if ($isHasMany) {
            // hasMany: szukaj w tabeli relacji po kluczu obcym
            $foreignKey = $this->getForeignKeyFromTableName($currentTableName);
            $relatedItems = [];
            
            foreach ($relationData as $relRow) {
                if (isset($relRow[$foreignKey]) && (string)$relRow[$foreignKey] === (string)$row['id']) {
                    $relatedItems[] = $relRow;
                }
            }
            
            $row[$relation] = $relatedItems;
        } else {
            // belongsTo: szukaj w tabeli relacji po ID
            $relationKey = rtrim($relation, 's') . '_id';
            
            if (isset($row[$relationKey])) {
                foreach ($relationData as $relRow) {
                    if (isset($relRow['id']) && (string)$relRow['id'] === (string)$row[$relationKey]) {
                        $row[$relation] = $relRow;
                        break;
                    }
                }
            }
        }
        
        return $row;
    }
    
    private function getForeignKeyFromTableName($tableName)
    {
        // Konwersja nazwy tabeli na klucz obcy
        $singularName = rtrim($tableName, 's');
        
        // Specjalne przypadki (jeśli potrzebne)
        $specialCases = [
            'categories' => 'category_id',
            'companies' => 'company_id',
            'countries' => 'country_id',
        ];
        
        if (isset($specialCases[$tableName])) {
            return $specialCases[$tableName];
        }
        
        return $singularName . '_id';
    }
    
    private function isHasManyRelation($tableName, $relation)
    {
        // Sprawdź czy to relacja hasMany na podstawie nazw
        // hasMany: nazwa relacji jest w liczbie mnogiej (products, categories)
        // belongsTo: nazwa relacji jest w liczbie pojedynczej (brand, category)
        
        $relationSingular = rtrim($relation, 's');
        $tableNameSingular = rtrim($tableName, 's');
        
        // Jeśli relacja kończy się na 's' i nie jest taka sama jak nazwa tabeli w liczbie pojedynczej
        // to prawdopodobnie to hasMany
        return (substr($relation, -1) === 's' && $relationSingular !== $tableNameSingular);
    }
    
    private function normalizeValue($value)
    {
        // Konwersja boolean strings na boolean
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        
        // Konwersja numeric strings na liczby
        if (is_string($value) && is_numeric($value)) {
            return (float)$value;
        }
        
        return $value;
    }
      private function compareValues($value1, $value2)
    {
        // Specjalne porównanie dla boolean - tylko jeśli jeden z argumentów to rzeczywiście boolean lub boolean string
        if (is_bool($value1) || is_bool($value2) || 
            in_array($value1, ['true', 'false'], true) || 
            in_array($value2, ['true', 'false'], true)) {
            
            $bool1 = $this->toBool($value1);
            $bool2 = $this->toBool($value2);
            return $bool1 === $bool2;
        }
        
        // Porównanie numeryczne
        if (is_numeric($value1) && is_numeric($value2)) {
            return (float)$value1 === (float)$value2;
        }
        
        // Porównanie stringów (case insensitive)
        if (is_string($value1) && is_string($value2)) {
            return trim(strtolower($value1)) === trim(strtolower($value2));
        }
        
        // Zwykłe porównanie
        return $value1 == $value2;
    }
    
    private function toBool($value)
    {
        if (is_bool($value)) return $value;
        if ($value === 'true' || $value === 1 || $value === '1') return true;
        if ($value === 'false' || $value === 0 || $value === '0') return false;
        return (bool)$value;
    }
    
    private function parseCreateData($dataString)
    {
        $data = [];
        $lines = explode(',', $dataString);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/[\'"](\w+)[\'"]\s*=>\s*(.+)/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2], '\'"');
                
                // Konwersja typów
                if ($value === 'true') $value = true;
                elseif ($value === 'false') $value = false;
                elseif (is_numeric($value)) $value = is_int($value + 0) ? (int)$value : (float)$value;
                
                $data[$key] = $value;
            }
        }
        
        return $data;
    }
}
