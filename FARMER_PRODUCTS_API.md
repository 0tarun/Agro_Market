# Farmer Products API Documentation

## File: farmer-products.php

This file handles both HTML rendering and API endpoints for farmer product management.

### Authentication
- Requires session authentication (user must be logged in)
- Only farmers can access this page/API
- User must have `is_active = 1` in the database

---

## HTML Page Request

### Display Products Page
**URL:** `farmer-products.php`  
**Method:** GET  
**Description:** Displays the HTML products management page

**Response:** HTML page with:
- Farmer's profile information in sidebar
- Product form to add new products
- Table of farmer's existing products
- Action menu for editing/deleting products

---

## API Endpoints

### 1. Get All Products (JSON)

**URL:** `farmer-products.php?action=list`  
**Method:** GET  
**Description:** Fetch all products for the logged-in farmer

**Response:**
```json
{
  "ok": true,
  "products": [
    {
      "id": 1,
      "name": "Organic Tomatoes",
      "price": "50.00",
      "stock_qty": 100,
      "category_name": "Vegetables",
      "image_path": "/image/uploads/products/product_1_1234567890.jpg",
      "description": "Fresh organic tomatoes"
    }
  ]
}
```

---

### 2. Get All Categories (JSON)

**URL:** `farmer-products.php?action=categories`  
**Method:** GET  
**Description:** Fetch all available product categories

**Response:**
```json
{
  "ok": true,
  "categories": [
    {"id": 1, "name": "Vegetables"},
    {"id": 2, "name": "Fruits"},
    {"id": 3, "name": "Grains"}
  ]
}
```

---

### 3. Add New Product

**URL:** `farmer-products.php?action=add`  
**Method:** POST  
**Content-Type:** `multipart/form-data`

**Form Parameters:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | string | Yes | Product name (max 140 chars) |
| price | float | Yes | Product price |
| stock_qty | integer | Yes | Stock quantity |
| category_id | integer | No | Category ID |
| description | string | No | Product description |
| image | file | No | Product image (max 5MB) |

**Example Request:**
```javascript
const formData = new FormData();
formData.append('name', 'Organic Rice');
formData.append('price', '100.50');
formData.append('stock_qty', '200');
formData.append('category_id', '1');
formData.append('description', 'Premium quality rice');
formData.append('image', fileInput.files[0]);

fetch('farmer-products.php?action=add', {
  method: 'POST',
  body: formData
})
.then(r => r.json())
.then(data => console.log(data));
```

**Success Response (201):**
```json
{
  "ok": true,
  "message": "Product added successfully",
  "product_id": 42
}
```

**Error Response (400/500):**
```json
{
  "ok": false,
  "message": "Product name is required and must be less than 140 characters"
}
```

---

### 4. Update Product

**URL:** `farmer-products.php?action=update`  
**Method:** PUT or POST  
**Content-Type:** `application/json`

**JSON Body:**
```json
{
  "product_id": 42,
  "name": "Updated Product Name",
  "price": 150.00,
  "stock_qty": 250,
  "category_id": 2,
  "description": "Updated description"
}
```

**Example Request:**
```javascript
fetch('farmer-products.php?action=update', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    product_id: 42,
    name: 'New Name',
    price: 150,
    stock_qty: 250,
    category_id: 2
  })
})
.then(r => r.json())
.then(data => console.log(data));
```

**Success Response:**
```json
{
  "ok": true,
  "message": "Product updated successfully"
}
```

**Error Response:**
```json
{
  "ok": false,
  "message": "You do not own this product"
}
```

---

### 5. Delete Product

**URL:** `farmer-products.php?action=delete`  
**Method:** POST  
**Content-Type:** `application/json`

**JSON Body:**
```json
{
  "product_id": 42
}
```

**Example Request:**
```javascript
fetch('farmer-products.php?action=delete', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    product_id: 42
  })
})
.then(r => r.json())
.then(data => console.log(data));
```

**Success Response:**
```json
{
  "ok": true,
  "message": "Product deleted successfully"
}
```

**Error Response:**
```json
{
  "ok": false,
  "message": "You do not own this product"
}
```

---

### 6. Upload Product Image

**URL:** `farmer-products.php?action=upload-image`  
**Method:** POST  
**Content-Type:** `multipart/form-data`

**Form Parameters:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| image | file | Yes | Image file (max 5MB) |

**Supported Formats:** JPEG, PNG, GIF, WebP

**Example Request:**
```javascript
const formData = new FormData();
formData.append('image', fileInput.files[0]);

fetch('farmer-products.php?action=upload-image', {
  method: 'POST',
  body: formData
})
.then(r => r.json())
.then(data => console.log(data));
```

**Success Response:**
```json
{
  "ok": true,
  "message": "Image uploaded successfully",
  "image_path": "/image/uploads/products/product_1_1234567890.jpg"
}
```

**Error Response:**
```json
{
  "ok": false,
  "message": "Invalid image file type"
}
```

---

## Error Codes

| Code | Status | Meaning |
|------|--------|---------|
| 401 | Unauthorized | User not logged in |
| 403 | Forbidden | User is not a farmer or doesn't own the product |
| 400 | Bad Request | Invalid input data |
| 405 | Method Not Allowed | Wrong HTTP method |
| 500 | Internal Server Error | Server error |

---

## Database Operations

**Tables Used:**
- `users` - Farmer information
- `products` - Product details
- `categories` - Product categories

**Image Storage:**
- Path: `/image/uploads/products/`
- Format: `product_[user_id]_[timestamp]_[unique_id].[ext]`
- Max size: 5MB
- Allowed types: JPEG, PNG, GIF, WebP

---

## Features

✅ Add new products with image upload  
✅ View all farmer's products  
✅ Update product details  
✅ Delete products (soft delete)  
✅ Image validation and secure upload  
✅ Category management  
✅ User authentication and authorization  
✅ Input validation and error handling  
✅ SQL injection prevention (PDO prepared statements)  

