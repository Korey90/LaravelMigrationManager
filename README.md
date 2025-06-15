# Laravel Migration Manager

Laravel 12 + React.js application with Inertia.js for managing database migrations through a modern 4-panel interface.

## Features

### 🔧 Migration Parser
- Parse Laravel migration syntax
- Generate table structures automatically
- Support for all Laravel column types (string, integer, decimal, boolean, timestamps, etc.)
- Handle indexes, foreign keys, and constraints

### 📊 Data Entry Interface
- Excel-like table editor
- Real-time validation
- Automatic default values
- Field highlighting for errors
- Support for complex data types

### 🔍 Advanced Query Builder
- Full Eloquent query support
- Multiple `where()` conditions with AND logic
- Closure-based conditional loading
- Nested relationships (`products.brand`, `products.category`)
- Support for operators: `=`, `>`, `<`, `>=`, `<=`, `like`, `!=`
- Boolean and numeric value handling

### 📤 Results Display
- Formatted JSON output
- Copy to clipboard functionality
- Real-time query execution
- Error handling and validation feedback

## Tech Stack

- **Backend**: Laravel 12 with Eloquent ORM
- **Frontend**: React.js with functional components and hooks
- **Bridge**: Inertia.js for seamless SPA experience
- **Styling**: Tailwind CSS for modern UI
- **Build Tool**: Vite for fast development and production builds

## Supported Query Types

### Basic Queries
```php
Product::all()
Product::find(1)
Product::where('is_active', true)->get()
Product::where('price', '>', 10)->first()
```

### Advanced Relationships
```php
// Closure conditions
Brand::with(['products' => function($query) { 
    $query->where('is_active', true)->where('category_id', 1); 
}])->get()

// Nested relationships
Category::with([
    'products' => function($query) { 
        $query->where('price', '>', 1.5); 
    }, 
    'products.brand',
    'products.supplier'
])->get()
```

### CRUD Operations
```php
// Create
Product::create(['name' => 'New Product', 'price' => 5.99])

// Update
Product::where('id', 1)->update(['price' => 3.99])

// Delete
Product::where('id', 1)->delete()
```

## Interface Layout

The application features a responsive 4-panel grid layout:

1. **Top-Left (Blue)**: Migration input and parsing
2. **Bottom-Left (Green)**: Interactive data tables with validation
3. **Top-Right (Purple)**: Eloquent query builder with model selection
4. **Bottom-Right (Red)**: Results output with copy functionality

## Installation

1. Clone the repository:
```bash
git clone https://github.com/Korey90/LaravelMigrationManager.git
cd LaravelMigrationManager
```

2. Install PHP dependencies:
```bash
composer install
```

3. Install Node.js dependencies:
```bash
npm install
```

4. Set up environment:
```bash
cp .env.example .env
php artisan key:generate
```

5. Run migrations:
```bash
php artisan migrate
```

6. Start development servers:
```bash
# Terminal 1 - Laravel server
php artisan serve

# Terminal 2 - Vite dev server
npm run dev
```

Visit `http://localhost:8000` to use the application.

## Development

### Backend Structure
- `app/Http/Controllers/MigrationController.php` - Main API logic
- `routes/api.php` - API endpoints
- `routes/web.php` - Inertia.js routes

### Frontend Structure
- `resources/js/Pages/Dashboard.jsx` - Main React component
- `resources/js/app.jsx` - Application entry point
- `resources/css/app.css` - Tailwind CSS styles

### Key API Endpoints
- `POST /api/parse-migrations` - Parse migration text and generate tables
- `POST /api/execute-query` - Execute Eloquent queries with simulation
- `POST /api/save-table-data` - Save and validate table data

## Features in Detail

### Migration Parsing
- Supports all Laravel schema builder methods
- Automatic table relationship detection
- Column type inference and validation rules
- Default value extraction

### Query Simulation
- Advanced Eloquent query parsing
- Relationship loading with conditions
- Type-safe value comparison
- Comprehensive error handling

### Data Validation
- Real-time field validation
- Type checking (string length, decimal precision, etc.)
- Uniqueness constraints
- Required field validation

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## License

This project is open-source and available under the MIT License.

## Author

Created by Korey90 with assistance from GitHub Copilot.
