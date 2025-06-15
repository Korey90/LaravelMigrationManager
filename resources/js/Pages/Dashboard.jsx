import React, { useState, useEffect } from 'react';
import axios from 'axios';

export default function Dashboard() {
    const [migrationText, setMigrationText] = useState('');
    const [tables, setTables] = useState({});
    const [selectedTable, setSelectedTable] = useState('');
    const [tableData, setTableData] = useState({});    const [eloquentQuery, setEloquentQuery] = useState('');
    const [queryResults, setQueryResults] = useState('');
    const [loading, setLoading] = useState(false);    const [validationErrors, setValidationErrors] = useState({});
    const [selectedModel, setSelectedModel] = useState('');
    const [copySuccess, setCopySuccess] = useState('');    // Kopiowanie wyników do schowka
    const copyToClipboard = async () => {
        if (!queryResults) {
            setCopySuccess('Brak wyników do skopiowania');
            setTimeout(() => setCopySuccess(''), 2000);
            return;
        }

        try {
            await navigator.clipboard.writeText(queryResults);
            setCopySuccess('Skopiowano do schowka!');
            setTimeout(() => setCopySuccess(''), 2000);
        } catch (err) {
            // Fallback dla starszych przeglądarek
            const textArea = document.createElement('textarea');
            textArea.value = queryResults;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            
            try {
                document.execCommand('copy');
                setCopySuccess('Skopiowano do schowka!');
            } catch (fallbackErr) {
                setCopySuccess('Błąd kopiowania');
            }
            
            document.body.removeChild(textArea);
            setTimeout(() => setCopySuccess(''), 2000);
        }
    };

    // Funkcja walidacji pojedynczego pola
    const validateField = (value, column, rowIndex, tableName) => {        const errors = [];
        
        // Sprawdź czy pole jest wymagane i puste (ignoruj pola z wartością domyślną)
        if (!value && !column.nullable && (column.default === undefined || column.default === null) && !column.autoFill && column.name !== 'id') {
            errors.push(`Pole '${column.name}' nie może być puste`);
        }
        
        // Walidacja długości string
        if (column.type === 'string' && column.maxLength && value && value.length > column.maxLength) {
            errors.push(`Przekracza maksymalną długość ${column.maxLength} znaków (aktualna: ${value.length})`);
        }
        
        // Walidacja unique
        if (column.unique && value && tableData[tableName]) {
            const duplicateExists = tableData[tableName].some((row, index) => 
                index !== rowIndex && row[column.name] === value
            );
            if (duplicateExists) {
                errors.push(`Wartość '${value}' musi być unikalna`);
            }
        }
        
        // Walidacja decimal
        if (column.type === 'decimal' && value) {
            if (!isNumeric(value)) {
                errors.push('Musi być liczbą');
            } else if (column.precision && column.scale) {
                const parts = value.toString().split('.');
                const integerPart = parts[0].replace('-', '');
                const decimalPart = parts[1] || '';
                
                const totalDigits = integerPart.length + decimalPart.length;
                if (totalDigits > column.precision) {
                    errors.push(`Przekracza precyzję ${column.precision} cyfr`);
                }
                if (decimalPart.length > column.scale) {
                    errors.push(`Przekracza ${column.scale} miejsc po przecinku`);
                }
            }
        }
        
        // Walidacja integer
        if ((column.type === 'integer' || column.type === 'bigint' || column.type === 'foreignId') && value) {
            if (!isNumeric(value) || !Number.isInteger(Number(value))) {
                errors.push('Musi być liczbą całkowitą');
            }
        }
        
        // Walidacja boolean
        if (column.type === 'boolean' && value && typeof value === 'string') {
            if (!['true', 'false', '1', '0', 'yes', 'no'].includes(value.toLowerCase())) {
                errors.push('Musi być wartością boolean (true/false, 1/0, yes/no)');
            }
        }
        
        return errors;
    };

    const isNumeric = (value) => {
        return !isNaN(value) && !isNaN(parseFloat(value));
    };

    // Aktualizacja danych z walidacją
    const updateTableData = (tableName, rowIndex, columnName, value) => {
        const newData = { ...tableData };
        if (!newData[tableName]) newData[tableName] = [];
        if (!newData[tableName][rowIndex]) newData[tableName][rowIndex] = {};
        
        newData[tableName][rowIndex][columnName] = value;
        setTableData(newData);
        
        // Walidacja w czasie rzeczywistym
        const column = tables[tableName]?.find(col => col.name === columnName);
        if (column) {
            const errors = validateField(value, column, rowIndex, tableName);
            const errorKey = `${tableName}-${rowIndex}-${columnName}`;
            
            const newValidationErrors = { ...validationErrors };
            if (errors.length > 0) {
                newValidationErrors[errorKey] = errors;
            } else {
                delete newValidationErrors[errorKey];
            }
            setValidationErrors(newValidationErrors);
        }
    };

    // Sprawdź czy pole ma błędy walidacji
    const hasValidationError = (tableName, rowIndex, columnName) => {
        const errorKey = `${tableName}-${rowIndex}-${columnName}`;
        return validationErrors[errorKey] && validationErrors[errorKey].length > 0;
    };

    // Pobierz błędy walidacji dla pola
    const getValidationErrors = (tableName, rowIndex, columnName) => {
        const errorKey = `${tableName}-${rowIndex}-${columnName}`;
        return validationErrors[errorKey] || [];
    };    // Zapis danych do tabeli
    const saveTableData = async () => {
        if (!selectedTable) {
            setQueryResults('Błąd: Wybierz tabelę.');
            return;
        }

        // Walidacja wszystkich pól przed zapisem
        const newValidationErrors = {};
        const data = tableData[selectedTable] || [];
        let hasErrors = false;
        let errorMessages = [];

        data.forEach((row, rowIndex) => {
            tables[selectedTable].forEach(column => {
                let value = row[column.name];
                
                // Automatycznie użyj wartości domyślnej jeśli pole jest puste
                if (!value && column.default !== undefined && column.default !== null) {
                    value = column.default;
                    // Aktualizuj dane w tabeli
                    const newData = { ...tableData };
                    if (!newData[selectedTable]) newData[selectedTable] = [];
                    if (!newData[selectedTable][rowIndex]) newData[selectedTable][rowIndex] = {};
                    newData[selectedTable][rowIndex][column.name] = value;
                    setTableData(newData);
                }
                
                const errors = validateField(value, column, rowIndex, selectedTable);
                
                if (errors.length > 0) {
                    const errorKey = `${selectedTable}-${rowIndex}-${column.name}`;
                    newValidationErrors[errorKey] = errors;
                    hasErrors = true;
                    
                    // Dodaj błędy do listy komunikatów
                    errors.forEach(error => {
                        errorMessages.push(`Wiersz ${rowIndex + 1}, kolumna '${column.name}': ${error}`);
                    });
                }
            });
        });

        // Aktualizuj błędy walidacji
        setValidationErrors(newValidationErrors);

        // Jeśli są błędy, wyświetl je w sekcji Wyniki
        if (hasErrors) {
            const errorText = `BŁĘDY WALIDACJI (${errorMessages.length}):\n\n${errorMessages.join('\n')}\n\nPopraw zaznaczone pola przed zapisaniem danych.`;
            setQueryResults(errorText);
            return;
        }

        setLoading(true);
        try {
            const response = await axios.post('/api/save-table-data', {
                table: selectedTable,
                data: tableData[selectedTable] || [],
                table_structure: tables[selectedTable] || []
            });
            
            // Aktualizuj dane z przetworzonymi wartościami
            if (response.data.processed_data) {
                const newData = { ...tableData };
                newData[selectedTable] = response.data.processed_data;
                setTableData(newData);
            }
            
            setQueryResults(`${response.data.message}\n\nPrzetworzone dane:\n${JSON.stringify(response.data.processed_data, null, 2)}`);
        } catch (error) {
            setQueryResults(`Błąd podczas zapisywania: ${error.response?.data?.message || error.message}`);
        }
        setLoading(false);
    };

    // Wykonanie zapytania Eloquent
    const executeQuery = async () => {
        if (!eloquentQuery.trim()) {
            setQueryResults('Błąd: Brak zapytania do wykonania.');
            return;
        }

        setLoading(true);
        try {
            const response = await axios.post('/api/execute-query', {
                query: eloquentQuery,
                table_data: tableData
            });
            
            // Jeśli to było zapytanie create, zaktualizuj dane tabeli
            if (response.data.result && response.data.result.action === 'create') {
                const newData = { ...tableData };
                const tableName = response.data.result.table;
                
                if (!newData[tableName]) {
                    newData[tableName] = [];
                }
                
                newData[tableName].push(response.data.result.data);
                setTableData(newData);
                
                setQueryResults(`${response.data.result.message}\n\nNowy rekord został dodany do tabeli ${tableName}:\n${JSON.stringify(response.data.result.data, null, 2)}\n\nAktualna liczba rekordów: ${newData[tableName].length}`);
            } else {
                setQueryResults(JSON.stringify(response.data.result, null, 2));
            }
        } catch (error) {
            setQueryResults(`Błąd podczas wykonywania zapytania: ${error.response?.data?.message || error.message}`);
        }
        setLoading(false);
    };

    // Parsowanie migracji
    const parseMigrations = async () => {
        if (!migrationText.trim()) {
            setQueryResults('Błąd: Brak kodu migracji do sparsowania.');
            return;
        }

        setLoading(true);
        try {
            const response = await axios.post('/api/parse-migrations', {
                migration_text: migrationText
            });
            
            setTables(response.data.tables);
            setTableData(response.data.table_data || {});
            setValidationErrors({}); // Wyczyść błędy walidacji
            setQueryResults(`Sparsowano ${Object.keys(response.data.tables).length} tabel: ${Object.keys(response.data.tables).join(', ')}`);
        } catch (error) {
            setQueryResults(`Błąd podczas parsowania: ${error.response?.data?.message || error.message}`);
        }
        setLoading(false);
    };

    // Dodawanie nowego wiersza do tabeli
    const addRow = () => {
        if (!selectedTable) {
            setQueryResults('Błąd: Wybierz tabelę przed dodaniem wiersza.');
            return;
        }
        
        const newData = { ...tableData };
        if (!newData[selectedTable]) newData[selectedTable] = [];
        
        const emptyRow = {};
        if (tables[selectedTable]) {
            tables[selectedTable].forEach(column => {
                emptyRow[column.name] = '';
            });
        }
        
        newData[selectedTable].push(emptyRow);
        setTableData(newData);
    };

    return (
        <div className="min-h-screen bg-gray-100">
            <div className="container mx-auto p-4">
                <h1 className="text-3xl font-bold text-center mb-6 text-gray-800">
                    Laravel Migration Manager
                </h1>
                
                <div className="grid grid-cols-2 gap-4 h-screen max-h-screen">
                    {/* Sektor 1: Lewy górny - Import migracji */}
                    <div className="bg-white rounded-lg shadow-lg p-6">
                        <h2 className="text-xl font-semibold mb-4 text-blue-600 border-b-2 border-blue-600 pb-2">
                            📄 Import Migracji
                        </h2>
                        <textarea
                            value={migrationText}
                            onChange={(e) => setMigrationText(e.target.value)}
                            placeholder="Wklej tutaj kod migracji Laravel...

Przykład:
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->timestamp('email_verified_at')->nullable();
    $table->string('password');
    $table->timestamps();
});

Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('content');
    $table->foreignId('user_id')->constrained();
    $table->timestamps();
});"
                            className="w-full h-80 p-3 border border-gray-300 rounded-md font-mono text-sm resize-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        />
                        <button
                            onClick={parseMigrations}
                            disabled={loading}
                            className="mt-4 bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 disabled:opacity-50"
                        >
                            {loading ? 'Parsowanie...' : 'Parsuj Migracje'}
                        </button>
                    </div>

                    {/* Sektor 2: Lewy dolny - Tabele z danymi */}
                    <div className="bg-white rounded-lg shadow-lg p-6">
                        <h2 className="text-xl font-semibold mb-4 text-green-600 border-b-2 border-green-600 pb-2">
                            📊 Dane Tabel
                        </h2>
                        <select
                            value={selectedTable}
                            onChange={(e) => setSelectedTable(e.target.value)}
                            className="w-full p-2 border border-gray-300 rounded-md mb-4 focus:ring-2 focus:ring-green-500"
                        >
                            <option value="">Wybierz tabelę...</option>
                            {Object.keys(tables).map(tableName => (
                                <option key={tableName} value={tableName}>{tableName}</option>
                            ))}
                        </select>                        {selectedTable && tables[selectedTable] ? (
                            <div className="overflow-auto max-h-80 border border-gray-300 rounded-md">
                                <table className="w-full border-collapse">
                                    <thead>
                                        <tr className="bg-gray-50">
                                            {tables[selectedTable].map(column => (
                                                <th key={column.name} className="border border-gray-300 p-2 text-left font-semibold">
                                                    {column.name}
                                                    {column.type && (
                                                        <div className="text-xs text-gray-500 font-normal">
                                                            {column.type}
                                                            {column.maxLength && ` (max: ${column.maxLength})`}
                                                            {column.precision && column.scale && ` (${column.precision},${column.scale})`}
                                                            {column.default && ` (default: ${column.default})`}
                                                            {column.autoIncrement && ' (auto)'}
                                                            {column.autoFill && ' (auto-fill)'}
                                                            {column.unique && ' (unique)'}
                                                            {column.nullable && ' (nullable)'}
                                                        </div>
                                                    )}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {(tableData[selectedTable] || [{}]).map((row, rowIndex) => (
                                            <tr key={rowIndex}>                                                {tables[selectedTable].map(column => (
                                                    <td key={column.name} className="border border-gray-300 p-1 relative">
                                                        <input
                                                            type={column.type === 'boolean' ? 'checkbox' : 
                                                                  column.type === 'integer' || column.type === 'bigint' || column.type === 'decimal' ? 'number' :
                                                                  column.type === 'timestamp' ? 'datetime-local' : 'text'}                                                            value={column.type === 'boolean' ? 
                                                                   (row[column.name] === true || row[column.name] === 'true') :
                                                                   (row[column.name] !== undefined && row[column.name] !== null ? row[column.name] : '')}
                                                            checked={column.type === 'boolean' ? 
                                                                     (row[column.name] === true || row[column.name] === 'true') : 
                                                                     undefined}
                                                            onChange={(e) => {
                                                                const value = column.type === 'boolean' ? e.target.checked : e.target.value;
                                                                updateTableData(selectedTable, rowIndex, column.name, value);
                                                            }}
                                                            placeholder={column.default ? `Default: ${column.default}` : 
                                                                        column.maxLength ? `Max ${column.maxLength} chars` : ''}
                                                            maxLength={column.maxLength || undefined}
                                                            step={column.type === 'decimal' && column.scale ? 
                                                                  Math.pow(10, -column.scale) : undefined}
                                                            className={`w-full p-1 border-none focus:bg-blue-50 focus:outline-none 
                                                                       ${hasValidationError(selectedTable, rowIndex, column.name) ? 
                                                                         'border-2 border-orange-700 bg-orange-50' : ''}`}
                                                            disabled={column.autoIncrement && column.name === 'id'}
                                                            title={column.unique ? 'Pole musi być unikalne' : 
                                                                   column.maxLength ? `Maksymalnie ${column.maxLength} znaków` : ''}
                                                        />
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>                                
                            </div>
                        ) : (
                            <p className="text-gray-500 text-center py-8">Najpierw sparsuj migracje i wybierz tabelę</p>
                        )}
                        
                        <div className="mt-4 space-x-2">
                            <button
                                onClick={addRow}
                                disabled={!selectedTable}
                                className="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 disabled:opacity-50"
                            >
                                Dodaj Wiersz
                            </button>
                            <button
                                onClick={saveTableData}
                                disabled={!selectedTable || loading}
                                className="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 disabled:opacity-50"
                            >
                                {loading ? 'Zapisywanie...' : 'Zapisz Dane'}
                            </button>
                        </div>
                    </div>

                    {/* Sektor 3: Prawy górny - Zapytania Eloquent */}
                    <div className="bg-white rounded-lg shadow-lg p-6">
                        <h2 className="text-xl font-semibold mb-4 text-purple-600 border-b-2 border-purple-600 pb-2">
                            🔍 Zapytania Eloquent
                        </h2>                        <select
                            value={selectedModel}
                            onChange={(e) => {
                                setSelectedModel(e.target.value);
                                // Automatycznie wstaw przykładowe zapytanie dla wybranego modelu
                                if (e.target.value) {
                                    setEloquentQuery(`${e.target.value}::all()`);
                                }
                            }}
                            className="w-full p-2 border border-gray-300 rounded-md mb-4 focus:ring-2 focus:ring-purple-500"
                        >
                            <option value="">Wybierz model...</option>
                            {Object.keys(tables).map(tableName => {
                                // Konwertuj nazwę tabeli na nazwę modelu (products -> Product)
                                const modelName = tableName.charAt(0).toUpperCase() + tableName.slice(1, -1);
                                return (
                                    <option key={tableName} value={modelName}>{modelName}</option>
                                );
                            })}
                        </select>
                          <textarea
                            value={eloquentQuery}
                            onChange={(e) => setEloquentQuery(e.target.value)}
                            placeholder="Wpisz zapytanie Eloquent...

Przykłady zapytań:
// Wszystkie produkty
Product::all()

// Znajdź produkt po ID
Product::find(1)

// Filtrowanie produktów
Product::where('is_active', true)->get()
Product::where('name', 'like', '%pomidor%')->first()
Product::where('price', '>', 2)->get()

// Relacje (jeśli dane istnieją w tabelach)
Product::with('brand')->get()
Product::with('category')->get()

// Tworzenie nowego produktu
Product::create([
    'ean' => '1234567890123',
    'name' => 'Nowy produkt',
    'price' => 5.99,
    'is_active' => true,
    'minimum_stock' => 5,
    'category_id' => 1,
    'brand_id' => 1
])

// Aktualizacja
Product::where('id', 1)->update(['price' => 3.99])

// Usuwanie
Product::where('id', 1)->delete()"
                            className="w-full h-60 p-3 border border-gray-300 rounded-md font-mono text-sm resize-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                        />
                        <button
                            onClick={executeQuery}
                            disabled={loading}
                            className="mt-4 bg-purple-600 text-white px-4 py-2 rounded-md hover:bg-purple-700 disabled:opacity-50"
                        >
                            {loading ? 'Wykonywanie...' : 'Wykonaj Zapytanie'}
                        </button>
                    </div>                    {/* Sektor 4: Prawy dolny - Wyniki */}
                    <div className="bg-white rounded-lg shadow-lg p-6">
                        <div className="flex justify-between items-center mb-4">
                            <h2 className="text-xl font-semibold text-red-600 border-b-2 border-red-600 pb-2">
                                📤 Wyniki
                            </h2>
                            <div className="flex items-center gap-2">
                                {copySuccess && (
                                    <span className="text-sm text-green-600 font-medium">
                                        {copySuccess}
                                    </span>
                                )}
                                <button
                                    onClick={copyToClipboard}
                                    disabled={!queryResults}
                                    className="bg-red-600 text-white px-3 py-1 rounded-md hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed text-sm font-medium transition-colors"
                                    title="Skopiuj wyniki do schowka"
                                >
                                    📋 Kopiuj
                                </button>
                            </div>
                        </div>
                        <div className="h-80 p-3 bg-gray-50 border border-gray-300 rounded-md overflow-auto">
                            <pre className="text-sm font-mono whitespace-pre-wrap">
                                {queryResults || 'Tutaj pojawią się wyniki zapytań i operacji...'}
                            </pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
