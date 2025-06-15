<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Database\Schema\Blueprint;

class MigrationController extends Controller
{
    public function parseMigrations(Request $request)
    {
        try {
            $migrationText = $request->input('migration_text');
            $tables = $this->parseMigrationText($migrationText);
            
            return response()->json([
                'success' => true,
                'tables' => $tables,
                'table_data' => []
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
                
                // Walidacja dla pustych wartości
                if (empty($value) && !isset($column['nullable']) && !isset($column['default']) && !isset($column['autoFill'])) {
                    if ($column['name'] !== 'id') {
                        $rowErrors[] = "Pole '{$columnName}' nie może być puste";
                    }
                }
                
                // Walidacja długości string
                if ($column['type'] === 'string' && isset($column['maxLength']) && !empty($value)) {
                    if (strlen($value) > $column['maxLength']) {
                        $rowErrors[] = "Pole '{$columnName}' przekracza maksymalną długość {$column['maxLength']} znaków (aktualna długość: " . strlen($value) . ")";
                    }
                }
                
                // Walidacja unique (podstawowa - sprawdza czy wartość się nie powtarza w tym samym zestawie danych)
                if (isset($column['unique']) && !empty($value)) {
                    $duplicateFound = false;
                    foreach ($data as $otherIndex => $otherRow) {
                        if ($otherIndex !== $index && isset($otherRow[$columnName]) && $otherRow[$columnName] === $value) {
                            $duplicateFound = true;
                            break;
                        }
                    }
                    if ($duplicateFound) {
                        $rowErrors[] = "Pole '{$columnName}' musi być unikalne, wartość '{$value}' już istnieje";
                    }
                }
                
                // Walidacja decimal
                if ($column['type'] === 'decimal' && !empty($value)) {
                    if (!is_numeric($value)) {
                        $rowErrors[] = "Pole '{$columnName}' musi być liczbą";
                    } elseif (isset($column['precision']) && isset($column['scale'])) {
                        $parts = explode('.', (string)$value);
                        $integerPart = $parts[0];
                        $decimalPart = isset($parts[1]) ? $parts[1] : '';
                        
                        $totalDigits = strlen(str_replace('-', '', $integerPart)) + strlen($decimalPart);
                        if ($totalDigits > $column['precision']) {
                            $rowErrors[] = "Pole '{$columnName}' przekracza maksymalną precyzję {$column['precision']} cyfr";
                        }
                        if (strlen($decimalPart) > $column['scale']) {
                            $rowErrors[] = "Pole '{$columnName}' przekracza maksymalną liczbę miejsc po przecinku {$column['scale']}";
                        }
                    }
                }
                
                // Walidacja boolean
                if ($column['type'] === 'boolean' && !empty($value)) {
                    if (!in_array(strtolower($value), ['true', 'false', '1', '0', 'yes', 'no'])) {
                        $rowErrors[] = "Pole '{$columnName}' musi być wartością boolean (true/false, 1/0, yes/no)";
                    }
                }
                
                // Walidacja integer
                if (($column['type'] === 'integer' || $column['type'] === 'bigint' || $column['type'] === 'foreignId') && !empty($value)) {
                    if (!is_numeric($value) || floor($value) != $value) {
                        $rowErrors[] = "Pole '{$columnName}' musi być liczbą całkowitą";
                    }
                }
                
                // Wartości domyślne
                if (empty($value) && isset($column['default'])) {
                    $defaultValue = $column['default'];
                    if ($defaultValue === 'true') $defaultValue = true;
                    elseif ($defaultValue === 'false') $defaultValue = false;
                    elseif (is_numeric($defaultValue)) $defaultValue = (int)$defaultValue;
                    $processedRow[$columnName] = $defaultValue;
                }
                
                // Auto-fill timestamps
                if (isset($column['autoFill']) && ($columnName === 'created_at' || $columnName === 'updated_at')) {
                    if (empty($value)) {
                        $processedRow[$columnName] = date('Y-m-d H:i:s');
                    }
                }
            }
            
            if (!empty($rowErrors)) {
                $errors["Wiersz " . ($index + 1)] = $rowErrors;
            }
            
            $processedData[] = $processedRow;
        }
        
        if (!empty($errors)) {
            throw new \Exception("Błędy walidacji:\n" . json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        
        return $processedData;
    }

    private function parseMigrationText($migrationText)
    {
        $tables = [];
        
        // Parsowanie Laravel Schema::create
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
            $tableName = $this->getTableNameFromModel($model);
            
            return $this->handleClosureQuery($model, $withContent, $tableData);
        }
        
        // Wielokrotne where z with - kompleksowe zapytania
        if (preg_match('/(\w+)::(?:where\([^)]+\)->)*where\([^)]+\)->with\([^)]+\)->get\(\)/', $query, $matches)) {
            return $this->handleMultipleWhereWithQuery($query, $tableData);
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
        
        if (preg_match('/(\w+)::where\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"]?([^,\)]+)[\'"]?\s*,\s*[\'"]?([^)]+)[\'"]?\s*\)->get\(\)/', $query, $matches)) {
            $model = $matches[1];
            $tableName = $this->getTableNameFromModel($model);
            $column = $matches[2];
            $operator = $matches[3];
            $value = trim($matches[4], '"\'');
            
            $data = $tableData[$tableName] ?? [];
            $filtered = [];
            
            foreach ($data as $row) {
                if (isset($row[$column])) {
                    $rowValue = $row[$column];
                    $match = false;
                    
                    switch ($operator) {
                        case '=':
                        case '==':
                            $match = $rowValue == $value;
                            break;
                        case 'like':
                            $pattern = str_replace('%', '.*', preg_quote($value, '/'));
                            $match = preg_match('/' . $pattern . '/i', $rowValue);
                            break;
                        case '>':
                            $match = $rowValue > $value;
                            break;
                        case '<':
                            $match = $rowValue < $value;
                            break;
                    }
                    
                    if ($match) {
                        $filtered[] = $row;
                    }
                }
            }
            
            return $filtered;
        }
        
        if (preg_match('/(\w+)::where\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"]?([^)]+)[\'"]?\s*\)->first\(\)/', $query, $matches)) {
            $model = $matches[1];
            $tableName = $this->getTableNameFromModel($model);
            $column = $matches[2];
            $value = trim($matches[3], '"\'');
            
            $data = $tableData[$tableName] ?? [];
            foreach ($data as $row) {
                if (isset($row[$column]) && $row[$column] == $value) {
                    return $row;
                }
            }
            return null;
        }
        
        if (preg_match('/(\w+)::find\((\d+)\)/', $query, $matches)) {
            $model = $matches[1];
            $id = $matches[2];
            $tableName = $this->getTableNameFromModel($model);
            $data = $tableData[$tableName] ?? [];
            
            foreach ($data as $row) {
                if (isset($row['id']) && $row['id'] == $id) {
                    return $row;
                }
            }
            return null;
        }
        
        // Zapytania where + with - format 3 parametrów: where('column', 'operator', 'value')
        if (preg_match('/(\w+)::where\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"]([^\'\"]+)[\'"]\s*,\s*[\'"]([^\'\"]+)[\'"]\s*\)->with\(\s*(.+?)\s*\)->get\(\)/', $query, $matches)) {
            $model = $matches[1];
            $column = $matches[2];
            $operator = $matches[3];
            $value = $matches[4];
            $relationsString = $matches[5];
            
            return $this->executeWhereWithQuery($model, $column, $operator, $value, $relationsString, $tableData);
        }
        
        // Zapytania where + with - format 2 parametrów: where('column', 'value') - operator = domyślnie
        if (preg_match('/(\w+)::where\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"]([^\'\"]+)[\'"]\s*\)->with\(\s*(.+?)\s*\)->get\(\)/', $query, $matches)) {
            $model = $matches[1];
            $column = $matches[2];
            $operator = '=';
            $value = $matches[3];
            $relationsString = $matches[4];
            
            return $this->executeWhereWithQuery($model, $column, $operator, $value, $relationsString, $tableData);
        }
        
        // Zapytania where + with - format bez cudzysłowów wokół wartości (np. liczby)
        if (preg_match('/(\w+)::where\(\s*[\'"](\w+)[\'"]\s*,\s*([^,)]+)\s*\)->with\(\s*(.+?)\s*\)->get\(\)/', $query, $matches)) {
            $model = $matches[1];
            $column = $matches[2];
            $operator = '=';
            $value = trim($matches[3], '\'" ');
            $relationsString = $matches[4];
            
            return $this->executeWhereWithQuery($model, $column, $operator, $value, $relationsString, $tableData);
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
    
    private function getTableNameFromModel($model)
    {
        // Normalizuj nazwę modelu (case insensitive)
        $modelNormalized = ucfirst(strtolower($model));
        
        // Mapowanie nazw modeli na nazwy tabel
        $modelToTableMap = [
            'Product' => 'products',
            'Brand' => 'brands', // Poprawione z 'brans' na 'brands'
            'Category' => 'categories',
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
    
    private function executeWhereWithQuery($model, $column, $operator, $value, $relationsString, $tableData)
    {
        $tableName = $this->getTableNameFromModel($model);
        $data = $tableData[$tableName] ?? [];
        
        // Debug info
        $debugInfo = [
            'model' => $model,
            'tableName' => $tableName,
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'value_type' => gettype($value),
            'data_count' => count($data),
            'sample_data' => array_slice($data, 0, 2),
            'all_column_values' => []
        ];
        
        // Zbierz wszystkie wartości kolumny do debugowania
        foreach ($data as $row) {
            if (isset($row[$column])) {
                $debugInfo['all_column_values'][] = [
                    'value' => $row[$column],
                    'type' => gettype($row[$column])
                ];
            }
        }
        
        // Najpierw filtruj po where
        $filteredData = [];
        foreach ($data as $row) {
            if (isset($row[$column])) {
                $rowValue = $row[$column];
                $match = false;
                
                // Normalizuj wartości boolean i numeryczne
                $value = $this->normalizeValue($value);
                $rowValue = $this->normalizeValue($rowValue);
                
                if ($operator === '=' || $operator === '==') {
                    // Sprawdź różne warianty porównania
                    $match = $rowValue === $value ||
                            (string)$rowValue === (string)$value ||
                            trim(strtolower((string)$rowValue)) === trim(strtolower((string)$value));
                } elseif ($operator === 'like') {
                    $pattern = str_replace('%', '.*', preg_quote($value, '/'));
                    $match = preg_match('/' . $pattern . '/i', $rowValue);
                } elseif ($operator === '>') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue > (float)$value;
                } elseif ($operator === '<') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue < (float)$value;
                } elseif ($operator === '>=') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue >= (float)$value;
                } elseif ($operator === '<=') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue <= (float)$value;
                }
                
                // Dodaj dodatkowy debug dla pierwszego elementu
                if (count($filteredData) === 0) {
                    $debugInfo['first_comparison'] = [
                        'row_value' => $rowValue,
                        'row_value_type' => gettype($rowValue),
                        'search_value' => $value,
                        'search_value_type' => gettype($value),
                        'operator' => $operator,
                        'match' => $match,
                        'string_comparison' => (string)$rowValue === (string)$value,
                        'case_insensitive' => trim(strtolower((string)$rowValue)) === trim(strtolower((string)$value))
                    ];
                }
                
                if ($match) {
                    $filteredData[] = $row;
                }
            }
        }
        
        $debugInfo['filtered_count'] = count($filteredData);
        
        // Parse relations
        $relations = [];
        if (preg_match_all('/[\'"]([^\'\"]+)[\'"]/', $relationsString, $relationMatches)) {
            $relations = $relationMatches[1];
        } elseif (preg_match('/\[(.+)\]/', $relationsString, $arrayMatch)) {
            if (preg_match_all('/[\'"]([^\'\"]+)[\'"]/', $arrayMatch[1], $relationMatches)) {
                $relations = $relationMatches[1];
            }
        }
        
        $debugInfo['relations'] = $relations;
        
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
        
        // Temporary debug
        // $result['_debug_where_with'] = $debugInfo;
        
        return $result;
    }
    
    private function normalizeValue($value)
    {
        // Normalizuj wartości boolean i numeryczne
        if ($value === 'true') $value = true;
        if ($value === 'false') $value = false;
        if (is_numeric($value)) $value = (float)$value;
        
        return $value;
    }
    
    private function getTableNameFromModel($model)
    {
        // Normalizuj nazwę modelu (case insensitive)
        $modelNormalized = ucfirst(strtolower($model));
        
        // Mapowanie nazw modeli na nazwy tabel
        $modelToTableMap = [
            'Product' => 'products',
            'Brand' => 'brands', // Poprawione z 'brans' na 'brands'
            'Category' => 'categories',
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
    
    private function executeWhereWithQuery($model, $column, $operator, $value, $relationsString, $tableData)
    {
        $tableName = $this->getTableNameFromModel($model);
        $data = $tableData[$tableName] ?? [];
        
        // Debug info
        $debugInfo = [
            'model' => $model,
            'tableName' => $tableName,
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'value_type' => gettype($value),
            'data_count' => count($data),
            'sample_data' => array_slice($data, 0, 2),
            'all_column_values' => []
        ];
        
        // Zbierz wszystkie wartości kolumny do debugowania
        foreach ($data as $row) {
            if (isset($row[$column])) {
                $debugInfo['all_column_values'][] = [
                    'value' => $row[$column],
                    'type' => gettype($row[$column])
                ];
            }
        }
        
        // Najpierw filtruj po where
        $filteredData = [];
        foreach ($data as $row) {
            if (isset($row[$column])) {
                $rowValue = $row[$column];
                $match = false;
                
                // Normalizuj wartości boolean i numeryczne
                $value = $this->normalizeValue($value);
                $rowValue = $this->normalizeValue($rowValue);
                
                if ($operator === '=' || $operator === '==') {
                    // Sprawdź różne warianty porównania
                    $match = $rowValue === $value ||
                            (string)$rowValue === (string)$value ||
                            trim(strtolower((string)$rowValue)) === trim(strtolower((string)$value));
                } elseif ($operator === 'like') {
                    $pattern = str_replace('%', '.*', preg_quote($value, '/'));
                    $match = preg_match('/' . $pattern . '/i', $rowValue);
                } elseif ($operator === '>') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue > (float)$value;
                } elseif ($operator === '<') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue < (float)$value;
                } elseif ($operator === '>=') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue >= (float)$value;
                } elseif ($operator === '<=') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue <= (float)$value;
                }
                
                // Dodaj dodatkowy debug dla pierwszego elementu
                if (count($filteredData) === 0) {
                    $debugInfo['first_comparison'] = [
                        'row_value' => $rowValue,
                        'row_value_type' => gettype($rowValue),
                        'search_value' => $value,
                        'search_value_type' => gettype($value),
                        'operator' => $operator,
                        'match' => $match,
                        'string_comparison' => (string)$rowValue === (string)$value,
                        'case_insensitive' => trim(strtolower((string)$rowValue)) === trim(strtolower((string)$value))
                    ];
                }
                
                if ($match) {
                    $filteredData[] = $row;
                }
            }
        }
        
        $debugInfo['filtered_count'] = count($filteredData);
        
        // Parse relations
        $relations = [];
        if (preg_match_all('/[\'"]([^\'\"]+)[\'"]/', $relationsString, $relationMatches)) {
            $relations = $relationMatches[1];
        } elseif (preg_match('/\[(.+)\]/', $relationsString, $arrayMatch)) {
            if (preg_match_all('/[\'"]([^\'\"]+)[\'"]/', $arrayMatch[1], $relationMatches)) {
                $relations = $relationMatches[1];
            }
        }
        
        $debugInfo['relations'] = $relations;
        
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
        
        // Temporary debug
        // $result['_debug_where_with'] = $debugInfo;
        
        return $result;
    }
    
    private function normalizeValue($value)
    {
        // Normalizuj wartości boolean i numeryczne
        if ($value === 'true') $value = true;
        if ($value === 'false') $value = false;
        if (is_numeric($value)) $value = (float)$value;
        
        return $value;
    }
    
    private function getTableNameFromModel($model)
    {
        // Normalizuj nazwę modelu (case insensitive)
        $modelNormalized = ucfirst(strtolower($model));
        
        // Mapowanie nazw modeli na nazwy tabel
        $modelToTableMap = [
            'Product' => 'products',
            'Brand' => 'brands', // Poprawione z 'brans' na 'brands'
            'Category' => 'categories',
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
    
    private function executeWhereWithQuery($model, $column, $operator, $value, $relationsString, $tableData)
    {
        $tableName = $this->getTableNameFromModel($model);
        $data = $tableData[$tableName] ?? [];
        
        // Debug info
        $debugInfo = [
            'model' => $model,
            'tableName' => $tableName,
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'value_type' => gettype($value),
            'data_count' => count($data),
            'sample_data' => array_slice($data, 0, 2),
            'all_column_values' => []
        ];
        
        // Zbierz wszystkie wartości kolumny do debugowania
        foreach ($data as $row) {
            if (isset($row[$column])) {
                $debugInfo['all_column_values'][] = [
                    'value' => $row[$column],
                    'type' => gettype($row[$column])
                ];
            }
        }
        
        // Najpierw filtruj po where
        $filteredData = [];
        foreach ($data as $row) {
            if (isset($row[$column])) {
                $rowValue = $row[$column];
                $match = false;
                
                // Normalizuj wartości boolean i numeryczne
                $value = $this->normalizeValue($value);
                $rowValue = $this->normalizeValue($rowValue);
                
                if ($operator === '=' || $operator === '==') {
                    // Sprawdź różne warianty porównania
                    $match = $rowValue === $value ||
                            (string)$rowValue === (string)$value ||
                            trim(strtolower((string)$rowValue)) === trim(strtolower((string)$value));
                } elseif ($operator === 'like') {
                    $pattern = str_replace('%', '.*', preg_quote($value, '/'));
                    $match = preg_match('/' . $pattern . '/i', $rowValue);
                } elseif ($operator === '>') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue > (float)$value;
                } elseif ($operator === '<') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue < (float)$value;
                } elseif ($operator === '>=') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue >= (float)$value;
                } elseif ($operator === '<=') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue <= (float)$value;
                }
                
                // Dodaj dodatkowy debug dla pierwszego elementu
                if (count($filteredData) === 0) {
                    $debugInfo['first_comparison'] = [
                        'row_value' => $rowValue,
                        'row_value_type' => gettype($rowValue),
                        'search_value' => $value,
                        'search_value_type' => gettype($value),
                        'operator' => $operator,
                        'match' => $match,
                        'string_comparison' => (string)$rowValue === (string)$value,
                        'case_insensitive' => trim(strtolower((string)$rowValue)) === trim(strtolower((string)$value))
                    ];
                }
                
                if ($match) {
                    $filteredData[] = $row;
                }
            }
        }
        
        $debugInfo['filtered_count'] = count($filteredData);
        
        // Parse relations
        $relations = [];
        if (preg_match_all('/[\'"]([^\'\"]+)[\'"]/', $relationsString, $relationMatches)) {
            $relations = $relationMatches[1];
        } elseif (preg_match('/\[(.+)\]/', $relationsString, $arrayMatch)) {
            if (preg_match_all('/[\'"]([^\'\"]+)[\'"]/', $arrayMatch[1], $relationMatches)) {
                $relations = $relationMatches[1];
            }
        }
        
        $debugInfo['relations'] = $relations;
        
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
        
        // Temporary debug
        // $result['_debug_where_with'] = $debugInfo;
        
        return $result;
    }
    
    private function normalizeValue($value)
    {
        // Normalizuj wartości boolean i numeryczne
        if ($value === 'true') $value = true;
        if ($value === 'false') $value = false;
        if (is_numeric($value)) $value = (float)$value;
        
        return $value;
    }
    
    private function getTableNameFromModel($model)
    {
        // Normalizuj nazwę modelu (case insensitive)
        $modelNormalized = ucfirst(strtolower($model));
        
        // Mapowanie nazw modeli na nazwy tabel
        $modelToTableMap = [
            'Product' => 'products',
            'Brand' => 'brands', // Poprawione z 'brans' na 'brands'
            'Category' => 'categories',
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
    
    private function executeWhereWithQuery($model, $column, $operator, $value, $relationsString, $tableData)
    {
        $tableName = $this->getTableNameFromModel($model);
        $data = $tableData[$tableName] ?? [];
        
        // Debug info
        $debugInfo = [
            'model' => $model,
            'tableName' => $tableName,
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'value_type' => gettype($value),
            'data_count' => count($data),
            'sample_data' => array_slice($data, 0, 2),
            'all_column_values' => []
        ];
        
        // Zbierz wszystkie wartości kolumny do debugowania
        foreach ($data as $row) {
            if (isset($row[$column])) {
                $debugInfo['all_column_values'][] = [
                    'value' => $row[$column],
                    'type' => gettype($row[$column])
                ];
            }
        }
        
        // Najpierw filtruj po where
        $filteredData = [];
        foreach ($data as $row) {
            if (isset($row[$column])) {
                $rowValue = $row[$column];
                $match = false;
                
                // Normalizuj wartości boolean i numeryczne
                $value = $this->normalizeValue($value);
                $rowValue = $this->normalizeValue($rowValue);
                
                if ($operator === '=' || $operator === '==') {
                    // Sprawdź różne warianty porównania
                    $match = $rowValue === $value ||
                            (string)$rowValue === (string)$value ||
                            trim(strtolower((string)$rowValue)) === trim(strtolower((string)$value));
                } elseif ($operator === 'like') {
                    $pattern = str_replace('%', '.*', preg_quote($value, '/'));
                    $match = preg_match('/' . $pattern . '/i', $rowValue);
                } elseif ($operator === '>') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue > (float)$value;
                } elseif ($operator === '<') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue < (float)$value;
                } elseif ($operator === '>=') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue >= (float)$value;
                } elseif ($operator === '<=') {
                    $match = is_numeric($rowValue) && is_numeric($value) && (float)$rowValue <= (float)$value;
                }
                
                // Dodaj dodatkowy debug dla pierwszego elementu
                if (count($filteredData) === 0) {
                    $debugInfo['first_comparison'] = [
                        'row_value' => $rowValue,
                        'row_value_type' => gettype($rowValue),
                        'search_value' => $value,
                        'search_value_type' => gettype($value),
                        'operator' => $operator,
                        'match' => $match,
                        'string_comparison' => (string)$rowValue === (string)$value,
                        'case_insensitive' => trim(strtolower((string)$rowValue)) === trim(strtolower((string)$value))
                    ];
                }
                
                if ($match) {
                    $filteredData[] = $row;
                }
            }
        }
        
        $debugInfo['filtered_count'] = count($filteredData);
        
        // Parse relations
        $relations = [];
        if (preg_match_all('/[\'"]([^\'\"]+)[\'"]/', $relationsString, $relationMatches)) {
            $relations = $relationMatches[1];
        } elseif (preg_match('/\[(.+)\]/', $relationsString, $arrayMatch)) {
            if (preg_match_all('/[\'"]([^\'\"]+)[\'"]/', $arrayMatch[1], $relationMatches)) {
                $relations = $relationMatches[1];
            }
        }
        
        $debugInfo['relations'] = $relations;
        
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
        
        // Temporary debug
        // $result['_debug_where_with'] = $debugInfo;
        
        return $result;
    }
    
    private function normalizeValue($value)
    {
        // Normalizuj wartości boolean i numeryczne
        if ($value === 'true') $value = true;
        if ($value === 'false') $value = false;
        if (is_numeric($value)) $value = (float)$value;
        
        return $value;
    }
    
    private function getTableNameFromModel($model)
    {
        // Normalizuj nazwę modelu (case insensitive)
        $modelNormalized = ucfirst(strtolower($model));
        
        // Mapowanie nazw modeli na nazwy tabel
        $modelToTableMap = [
            'Product' => 'products',
            'Brand' => 'brands', // Poprawione z 'brans' na 'brands'
            'Category' => 'categories',
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
        }
        
        // Parsuj zwykłe relacje: 'relation1', 'relation2'
        if (preg_match_all('/[\'"](\w+)[\'"]\s*(?!=>)/', $withContent, $simpleMatches)) {
            foreach ($simpleMatches[1] as $relation) {
                if (!isset($relations[$relation])) {
                    $relations[$relation] = [];
                }
            }
        }
        
        $result = [];
        foreach ($mainData as $row) {
            $rowWithRelations = $row;
            
            foreach ($relations as $relationName => $conditions) {
                $rowWithRelations = $this->addRelationWithConditions($rowWithRelations, $relationName, $conditions, $tableName, $tableData);
            }
            
            $result[] = $rowWithRelations;
        }
        
        return $result;
    }
    
    private function parseClosureConditions($closureBody)
    {
        $conditions = [];
        
        // Znajdź wszystkie where() w closure
        if (preg_match_all('/\$query->where\(\s*[\'"](\w+)[\'"]\s*,\s*[\'"]?([^,)]+)[\'"]?\s*(?:,\s*[\'"]?([^)]+)[\'"]?)?\s*\)/', $closureBody, $whereMatches, PREG_SET_ORDER)) {
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
        
        return $conditions;
    }
    
    private function addRelationWithConditions($row, $relationName, $conditions, $currentTableName, $tableData)
    {
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
        if ($value === 'true') $value = true;
        if ($value === 'false') $value = false;
        if ($rowValue === 'true') $rowValue = true;
        if ($rowValue === 'false') $rowValue = false;
        
        switch ($operator) {
            case '=':
            case '==':
                return $this->compareValues($rowValue, $value, '=');
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
    
    private function compareValues($value1, $value2, $operator = '=')
    {
        // Specjalne porównanie dla boolean
        if (is_bool($value1) || is_bool($value2) || 
            in_array($value1, ['true', 'false', true, false]) || 
            in_array($value2, ['true', 'false', true, false])) {
            
            $bool1 = $this->toBool($value1);
            $bool2 = $this->toBool($value2);
            return $bool1 === $bool2;
        }
        
        // Porównanie stringów (case insensitive)
        if (is_string($value1) && is_string($value2)) {
            return trim(strtolower($value1)) === trim(strtolower($value2));
        }
        
        // Porównanie numeryczne
        if (is_numeric($value1) && is_numeric($value2)) {
            return (float)$value1 === (float)$value2;
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
}
