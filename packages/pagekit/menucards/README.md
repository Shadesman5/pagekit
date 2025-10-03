# Menucards Extension

Digital menu card management system with centralized product management for Pagekit CMS.

## Features

- **Global Product Management**: Centralized product database with name, description, price, allergens, and images
- **Menu Card Management**: Create and manage multiple menu cards with categories
- **Many-to-Many Relationships**: Assign the same product to multiple categories across different menus
- **Contextual Product Creation**: Create new products directly from the menu editing view via modal
- **Public Display**: Beautiful public-facing menu card display with SEO-friendly URLs

## Installation

1. Place the `menucards` folder in `/packages/pagekit/`
2. Go to Pagekit Admin → Extensions
3. Enable the "Menucards" extension
4. Database tables will be created automatically

## Usage

### Managing Products

1. Navigate to **Menucards** → **Products** in the admin panel
2. Create, edit, or delete products in the global product list
3. Products can be reused across multiple menu cards and categories

### Creating Menu Cards

1. Navigate to **Menucards** in the admin panel
2. Create a new menu card with title and slug
3. Add categories to organize your products
4. Assign existing products to categories OR create new products on-the-fly using the modal

### Contextual Product Creation

When editing a menu card:
1. Open a category
2. Click "Create New Product" button
3. Fill in product details in the modal
4. Product is automatically created in the global list AND assigned to the current category

### Public Display

Access your menu cards at:
```
https://yoursite.com/menucard/{slug}
```

## Technical Details

### Database Schema

- `pk_menucards_menu`: Menu cards table
- `pk_menucards_category`: Categories table (one-to-many with menus)
- `pk_menucards_product`: Global products table
- `pk_menucards_category_product`: Many-to-many relationship table

### API Endpoints

- `GET/POST /api/menucards/product`: Product CRUD operations
- `GET/POST /api/menucards/menu`: Menu CRUD operations
- `GET/POST /api/menucards/category`: Category CRUD operations
- `POST /api/menucards/category/product`: Assign/remove products from categories

## License

MIT License
