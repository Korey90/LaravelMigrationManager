<!-- Use this file to provide workspace-specific custom instructions to Copilot. For more details, visit https://code.visualstudio.com/docs/copilot/copilot-customization#_use-a-githubcopilotinstructionsmd-file -->

# Laravel Migration Manager - Copilot Instructions

This is a Laravel 12 project with React.js frontend using Inertia.js for building a four-panel database migration management interface.

## Project Structure
- **Laravel 12** backend with Eloquent ORM
- **React.js** frontend with **Inertia.js** integration
- **Vite** for asset bundling and development
- **Tailwind CSS** for styling

## Key Features
1. **Migration Parser**: Parses Laravel migration syntax and generates table structures
2. **Data Entry**: Excel-like interface for entering sample data
3. **Query Builder**: Interface for writing and executing Eloquent queries
4. **Results Display**: Shows query results and operation feedback

## File Organization
- `resources/js/Pages/Dashboard.jsx` - Main React component with 4-panel interface
- `app/Http/Controllers/MigrationController.php` - Backend API logic
- `routes/api.php` - API endpoints for migration parsing and query execution
- `routes/web.php` - Web routes using Inertia.js

## Development Guidelines
- Use React functional components with hooks
- Follow Laravel conventions for API responses
- Use Tailwind CSS classes for styling
- Keep the 4-panel layout responsive and user-friendly
- Simulate database operations for demonstration purposes

## API Endpoints
- `POST /api/parse-migrations` - Parse migration text
- `POST /api/execute-query` - Execute Eloquent queries
- `POST /api/save-table-data` - Save table data

## Styling
The interface uses a 4-panel grid layout:
- Top-left: Migration input (blue theme)
- Bottom-left: Data tables (green theme)
- Top-right: Query builder (purple theme)
- Bottom-right: Results output (red theme)
