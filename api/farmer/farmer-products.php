<?php

declare(strict_types=1);
session_start();
require_once __DIR__ . '/../../config/database.php';

// Check if user is logged in and is a farmer
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ../../pages/auth/Login.html?error=' . urlencode('Please login first'));
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['is_active'] !== 1) {
        header('Location: ../auth/logout.php');
        exit;
    }

    $role = strtolower(trim((string)($user['role'] ?? '')));
    if ($role === 'customer') {
        $role = 'consumer';
    }

    if ($role !== 'farmer') {
        header('Location: ../../pages/farmer/customer-details.html');
        exit;
    }

    // Get farmer profile info
    $stmt = $pdo->prepare(
        'SELECT full_name, profile_image, division, district FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $farmer = $stmt->fetch();

    // Get categories
    $stmt = $pdo->prepare('SELECT id, name FROM categories ORDER BY name ASC');
    $stmt->execute();
    $categories = $stmt->fetchAll();

    // Get farmer's products
    $stmt = $pdo->prepare(
        'SELECT p.id, p.name, p.price, p.stock_qty, p.harvest_date, p.created_at, c.id AS category_id, c.name as category_name, p.image_path
         FROM products p
         LEFT JOIN categories c ON p.category_id = c.id
         WHERE p.farmer_id = :farmer_id AND p.is_active = 1
         ORDER BY p.created_at DESC'
    );
    $stmt->execute(['farmer_id' => $userId]);
    $products = $stmt->fetchAll();
} catch (Throwable $e) {
    header('Location: ../../pages/farmer/farmer-dashboard.html?error=' . urlencode('Error loading page'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Farmer Products - AgroMarket</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet" />
    <link rel="stylesheet" href="assets/css/farmer-dashboard.css" />
    <link rel="stylesheet" href="assets/css/farmer-products.css" />
    <style>
        .error-message {
            color: #e74c3c;
            background-color: #fadbd8;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 10px;
            display: none;
        }

        .success-message {
            color: #27ae60;
            background-color: #d5f4e6;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 10px;
            display: none;
        }

        .action-menu {
            position: fixed;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            display: none;
            min-width: 100px;
        }

        .action-menu button {
            display: block;
            width: 100%;
            padding: 10px 15px;
            border: none;
            background: none;
            text-align: left;
            cursor: pointer;
            font-family: inherit;
            font-size: 14px;
            color: #333;
        }

        .action-menu button:hover {
            background-color: #f5f5f5;
        }

        .action-menu button:first-child {
            border-radius: 4px 4px 0 0;
        }

        .action-menu button:last-child {
            border-radius: 0 0 4px 4px;
        }

        .action-menu button.delete {
            color: #e74c3c;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #888;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #333;
        }

        .modal-close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 20px;
        }

        .modal-close:hover {
            color: #000;
        }

        .modal-form-grid {
            display: grid;
            gap: 15px;
        }

        .modal-form-grid label {
            display: flex;
            flex-direction: column;
            gap: 5px;
            color: #333;
        }

        .modal-form-grid input,
        .modal-form-grid select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: inherit;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
        }

        .modal-buttons button {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-family: inherit;
        }

        .modal-buttons .cancel-btn {
            background-color: #95a5a6;
            color: white;
        }

        .modal-buttons .save-btn {
            background-color: #27ae60;
            color: white;
        }

        .modal-buttons button:hover {
            opacity: 0.9;
        }
    </style>
</head>

<body>
    <main class="dashboard-page">
        <aside class="sidebar">
            <div>
                <div class="profile-block">
                    <img id="sidebarProfileImage" src="<?php echo htmlspecialchars($farmer['profile_image'] ?? 'figma/images (5).jpg'); ?>" alt="Farmer profile" class="profile-image" />
                    <h2 id="sidebarProfileName" class="profile-name"><?php echo htmlspecialchars($farmer['full_name'] ?? 'My Farmer Account'); ?></h2>
                    <p id="sidebarProfileLocation" class="profile-location">
                        <?php
                        $location = [];
                        if (!empty($farmer['district'])) $location[] = $farmer['district'];
                        if (!empty($farmer['division'])) $location[] = $farmer['division'];
                        echo htmlspecialchars(implode(', ', $location) ?: 'Location...');
                        ?>
                    </p>
                </div>

                <nav class="menu" aria-label="Sidebar menu">
                    <a href="index.html" class="menu-item">
                        <span class="menu-icon">&#8962;</span>
                        Home
                    </a>
                    <a href="pages/farmer/farmer-dashboard.html" class="menu-item">
                        <span class="menu-icon">&#9638;</span>
                        Overview
                    </a>
                    <a href="farmer-products.php" class="menu-item active">
                        <span class="menu-icon">&#9635;</span>
                        Products
                    </a>
                    <a href="pages/farmer/farmer-orders.html" class="menu-item">
                        <span class="menu-icon">&#9745;</span>
                        Orders
                    </a>
                    <a href="pages/farmer/farmer-feedback.html" class="menu-item">
                        <span class="menu-icon">&#9993;</span>
                        Feedback
                    </a>
                    <a href="pages/farmer/farmer-help-support.html" class="menu-item">
                        <span class="menu-icon">?</span>
                        Help and Support
                    </a>
                    <a href="pages/farmer/farmer-account.html" class="menu-item">
                        <span class="menu-icon">&#9787;</span>
                        Account
                    </a>
                </nav>
            </div>

            <a href="logout.php" class="logout-btn">Logout</a>
        </aside>

        <section class="main-panel products-main">
            <h1 class="products-title">Product List</h1>

            <section class="panel product-form-panel">
                <div class="error-message" id="errorMsg"></div>
                <div class="success-message" id="successMsg"></div>

                <form id="productForm" enctype="multipart/form-data" method="POST" action="">
                    <div class="product-form-grid">
                        <div class="upload-row">
                            <input type="file" id="productImage" name="productImage" accept="image/*" style="display: none;" />
                            <button type="button" class="upload-btn" onclick="document.getElementById('productImage').click();">Upload Image</button>
                            <span class="file-name" id="fileName">No image selected</span>
                        </div>

                        <label>
                            Product Name
                            <input type="text" id="productName" name="productName" placeholder="Enter Product Name" required />
                        </label>

                        <label>
                            Stock
                            <input type="number" id="productStock" name="productStock" placeholder="Enter Stock Quantity" min="0" required />
                        </label>

                        <label>
                            Category
                            <select id="productCategory" name="productCategory" required>
                                <option value="">Select category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars((string)$cat['id']); ?>">
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            Price
                            <input type="number" id="productPrice" name="productPrice" placeholder="Enter Price" min="0" step="0.01" required />
                        </label>

                        <label>
                            Harvest Date
                            <input type="date" id="productHarvestDate" name="productHarvestDate" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required />
                        </label>

                        <div class="post-box">
                            <p>Note: Ensure that all information are correct.</p>
                            <button type="submit" class="post-btn">Post product</button>
                        </div>
                    </div>
                </form>
            </section>

            <section class="list-wrap">
                <div class="list-head">
                    <span>IMAGE</span>
                    <span>PRODUCT</span>
                    <span>PRICE</span>
                    <span>HARVEST</span>
                    <span>STATUS</span>
                    <span>STOCK</span>
                    <span>CATEGORY</span>
                    <span>ACTION</span>
                </div>

                <?php if (empty($products)): ?>
                    <div style="padding: 20px; text-align: center; color: #999;">
                        <p>No products added yet. Create your first product above!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                        <?php $pricing = calculatePerishableProductPricing($product); ?>
                        <article
                            class="list-row"
                            data-product-id="<?php echo htmlspecialchars((string)$product['id']); ?>"
                            data-product-name="<?php echo htmlspecialchars($product['name']); ?>"
                            data-product-price="<?php echo htmlspecialchars(number_format((float)$product['price'], 2, '.', '')); ?>"
                            data-product-stock="<?php echo htmlspecialchars((string)$product['stock_qty']); ?>"
                            data-category-id="<?php echo htmlspecialchars((string)($product['category_id'] ?? '')); ?>"
                            data-category-name="<?php echo htmlspecialchars($product['category_name'] ?? ''); ?>"
                            data-harvest-date="<?php echo htmlspecialchars((string)($product['harvest_date'] ?? '')); ?>">
                            <div class="image-col">
                                <img src="<?php echo htmlspecialchars($product['image_path'] ?? '/figma/images (2).jpg'); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" />
                            </div>
                            <p><?php echo htmlspecialchars($product['name']); ?></p>
                            <div class="price-cell">
                                <p>৳<?php echo htmlspecialchars(number_format((float)$pricing['effective_price'], 2)); ?></p>
                                <?php if (!empty($pricing['is_expired'])): ?>
                                    <small class="status-badge expired">Expired</small>
                                <?php elseif ((int)$pricing['discount_percent'] > 0): ?>
                                    <small class="status-badge discounted">20% off</small>
                                <?php else: ?>
                                    <small class="status-badge fresh">Full price</small>
                                <?php endif; ?>
                            </div>
                            <p><?php echo htmlspecialchars((string)($product['harvest_date'] ?? '')); ?></p>
                            <p><?php echo htmlspecialchars((string)$pricing['pricing_label']); ?></p>
                            <p><?php echo htmlspecialchars((string)$product['stock_qty']); ?></p>
                            <p><?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></p>
                            <p class="action-dot" onclick="showActionMenu(event, <?php echo htmlspecialchars((string)$product['id']); ?>)">...</p>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </section>
    </main>

    <!-- Action Menu -->
    <div class="action-menu" id="actionMenu">
        <button onclick="openEditModal()">✏️ Edit</button>
        <button class="delete" onclick="deleteProduct()">🗑️ Delete</button>
    </div>

    <!-- Edit Modal -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeEditModal()">&times;</span>
            <div class="modal-header">Edit Product</div>
            <form id="editForm">
                <div class="modal-form-grid">
                    <label>
                        Product Name
                        <input type="text" id="editProductName" required />
                    </label>
                    <label>
                        Stock
                        <input type="number" id="editProductStock" min="0" required />
                    </label>
                    <label>
                        Category
                        <select id="editProductCategory" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars((string)$cat['id']); ?>">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Price
                        <input type="number" id="editProductPrice" min="0" step="0.01" required />
                    </label>
                    <label>
                        Harvest Date
                        <input type="date" id="editProductHarvestDate" required />
                    </label>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="cancel-btn" onclick="closeEditModal()">Cancel</button>
                    <button type="button" class="save-btn" onclick="saveEditProduct()">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/js/farmer-sidebar.js"></script>
    <script>
        let selectedImageFile = null;
        let currentProductId = null;

        // Handle image selection
        document.getElementById('productImage').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                selectedImageFile = file;
                document.getElementById('fileName').textContent = file.name;
            }
        });

        // Show action menu
        function showActionMenu(event, productId) {
            event.stopPropagation();
            currentProductId = productId;
            const menu = document.getElementById('actionMenu');
            menu.style.display = 'block';
            menu.style.left = event.pageX + 'px';
            menu.style.top = event.pageY + 'px';
        }

        // Close action menu when clicking elsewhere
        document.addEventListener('click', function(event) {
            const menu = document.getElementById('actionMenu');
            if (!event.target.closest('.action-dot') && !event.target.closest('#actionMenu')) {
                menu.style.display = 'none';
            }
        });

        // Open edit modal
        function openEditModal() {
            if (!currentProductId) return;

            // Find product data from the row
            const row = document.querySelector('[data-product-id="' + currentProductId + '"]');
            if (!row) return;

            document.getElementById('editProductName').value = row.dataset.productName || '';
            document.getElementById('editProductPrice').value = row.dataset.productPrice || '0';
            document.getElementById('editProductStock').value = row.dataset.productStock || '0';
            document.getElementById('editProductHarvestDate').value = row.dataset.harvestDate || '';
            document.getElementById('editProductCategory').value = row.dataset.categoryId || '';

            document.getElementById('editModal').style.display = 'block';
            document.getElementById('actionMenu').style.display = 'none';
        }

        // Close edit modal
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Save edited product
        async function saveEditProduct() {
            if (!currentProductId) return;

            const productName = document.getElementById('editProductName').value.trim();
            const productPrice = parseFloat(document.getElementById('editProductPrice').value);
            const productStock = parseInt(document.getElementById('editProductStock').value);
            const productCategory = document.getElementById('editProductCategory').value;
            const harvestDate = document.getElementById('editProductHarvestDate').value;

            // Validation
            if (!productName) {
                showError('Please enter product name');
                return;
            }
            if (isNaN(productPrice) || productPrice <= 0) {
                showError('Please enter valid price');
                return;
            }
            if (isNaN(productStock) || productStock < 0) {
                showError('Please enter valid stock quantity');
                return;
            }
            if (!productCategory) {
                showError('Please select a category');
                return;
            }
            if (!harvestDate) {
                showError('Please select a harvest date');
                return;
            }

            try {
                const response = await fetch('farmer-products-handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'update_product',
                        product_id: currentProductId,
                        product_name: productName,
                        product_price: productPrice,
                        product_stock: productStock,
                        product_category: productCategory,
                        harvest_date: harvestDate
                    })
                });

                const data = await response.json();

                if (data.success) {
                    showSuccess('Product updated successfully!');
                    closeEditModal();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showError('Error: ' + (data.message || 'Failed to update product'));
                }
            } catch (error) {
                showError('Error: ' + error.message);
                console.error('Error:', error);
            }
        }

        // Handle form submission
        document.getElementById('productForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const productName = document.getElementById('productName').value.trim();
            const productPrice = parseFloat(document.getElementById('productPrice').value);
            const productStock = parseInt(document.getElementById('productStock').value);
            const productCategory = document.getElementById('productCategory').value;
            const harvestDate = document.getElementById('productHarvestDate').value;

            // Validation
            if (!productName) {
                showError('Please enter product name');
                return;
            }
            if (isNaN(productPrice) || productPrice <= 0) {
                showError('Please enter valid price');
                return;
            }
            if (isNaN(productStock) || productStock < 0) {
                showError('Please enter valid stock quantity');
                return;
            }
            if (!productCategory) {
                showError('Please select a category');
                return;
            }
            if (!harvestDate) {
                showError('Please select a harvest date');
                return;
            }

            // Create FormData
            const formData = new FormData();
            formData.append('action', 'add_product');
            formData.append('product_name', productName);
            formData.append('product_price', productPrice.toString());
            formData.append('product_stock', productStock.toString());
            formData.append('product_category', productCategory);
            formData.append('harvest_date', harvestDate);

            if (selectedImageFile) {
                formData.append('product_image', selectedImageFile);
            }

            try {
                const response = await fetch('farmer-products-handler.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    showSuccess('Product created successfully!');
                    document.getElementById('productForm').reset();
                    selectedImageFile = null;
                    document.getElementById('fileName').textContent = 'No image selected';
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showError('Error: ' + (data.message || 'Failed to create product'));
                }
            } catch (error) {
                showError('Error: ' + error.message);
                console.error('Error:', error);
            }
        });

        // Handle product deletion
        async function deleteProduct() {
            if (!currentProductId) return;

            if (!confirm('Are you sure you want to delete this product?')) {
                return;
            }

            try {
                const response = await fetch('farmer-products-handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'delete_product',
                        product_id: currentProductId
                    })
                });

                const data = await response.json();

                if (data.success) {
                    showSuccess('Product deleted successfully!');
                    document.getElementById('actionMenu').style.display = 'none';
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showError('Error: ' + (data.message || 'Failed to delete product'));
                }
            } catch (error) {
                showError('Error: ' + error.message);
                console.error('Error:', error);
            }
        }

        function showError(message) {
            const errorDiv = document.getElementById('errorMsg');
            errorDiv.textContent = message;
            errorDiv.style.display = 'block';
            document.getElementById('successMsg').style.display = 'none';
        }

        function showSuccess(message) {
            const successDiv = document.getElementById('successMsg');
            successDiv.textContent = message;
            successDiv.style.display = 'block';
            document.getElementById('errorMsg').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('editModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>

</html>