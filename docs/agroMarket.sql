

CREATE DATABASE IF NOT EXISTS agromarket
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE agromarket;

CREATE TABLE users (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
role ENUM('farmer','consumer','admin') NOT NULL DEFAULT 'consumer',
full_name VARCHAR(120) NOT NULL,
email VARCHAR(190) NOT NULL UNIQUE,
password_hash VARCHAR(255) NOT NULL,
phone VARCHAR(25) NULL,
profile_image VARCHAR(255) NULL,
division VARCHAR(80) NULL,
district VARCHAR(80) NULL,
address_line VARCHAR(255) NULL,
is_active TINYINT(1) NOT NULL DEFAULT 1,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE categories (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(80) NOT NULL UNIQUE,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE products (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
farmer_id BIGINT UNSIGNED NOT NULL,
category_id BIGINT UNSIGNED NULL,
name VARCHAR(140) NOT NULL,
description TEXT NULL,
harvest_date DATE NOT NULL,
price DECIMAL(10,2) NOT NULL,
stock_qty INT NOT NULL DEFAULT 0,
image_path VARCHAR(255) NULL,
is_active TINYINT(1) NOT NULL DEFAULT 1,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
CONSTRAINT fk_products_farmer
FOREIGN KEY (farmer_id) REFERENCES users(id)
ON DELETE CASCADE,
CONSTRAINT fk_products_category
FOREIGN KEY (category_id) REFERENCES categories(id)
ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE orders (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
order_code VARCHAR(30) NOT NULL UNIQUE,
consumer_id BIGINT UNSIGNED NOT NULL,
farmer_id BIGINT UNSIGNED NOT NULL,
status ENUM('pending','to_receive','completed','refund_return','cancelled') NOT NULL DEFAULT 'pending',
payment_status ENUM('pending','transferred','received','failed','not_received') NOT NULL DEFAULT 'pending',
payment_method ENUM('bkash','nagad','bank_transfer','cash') NOT NULL DEFAULT 'cash',
total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
shipping_address VARCHAR(255) NULL,
placed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
CONSTRAINT fk_orders_consumer
FOREIGN KEY (consumer_id) REFERENCES users(id)
ON DELETE RESTRICT,
CONSTRAINT fk_orders_farmer
FOREIGN KEY (farmer_id) REFERENCES users(id)
ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE order_items (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
order_id BIGINT UNSIGNED NOT NULL,
product_id BIGINT UNSIGNED NULL,
product_name_snapshot VARCHAR(140) NOT NULL,
qty DECIMAL(10,2) NOT NULL,
unit_price DECIMAL(10,2) NOT NULL,
line_total DECIMAL(12,2) NOT NULL,
CONSTRAINT fk_order_items_order
FOREIGN KEY (order_id) REFERENCES orders(id)
ON DELETE CASCADE,
CONSTRAINT fk_order_items_product
FOREIGN KEY (product_id) REFERENCES products(id)
ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE message_threads (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
farmer_id BIGINT UNSIGNED NOT NULL,
consumer_id BIGINT UNSIGNED NOT NULL,
subject VARCHAR(180) NULL,
last_message_at DATETIME NULL,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
CONSTRAINT fk_threads_farmer
FOREIGN KEY (farmer_id) REFERENCES users(id)
ON DELETE CASCADE,
CONSTRAINT fk_threads_consumer
FOREIGN KEY (consumer_id) REFERENCES users(id)
ON DELETE CASCADE,
UNIQUE KEY uq_thread_pair (farmer_id, consumer_id)
) ENGINE=InnoDB;

CREATE TABLE messages (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
thread_id BIGINT UNSIGNED NOT NULL,
sender_id BIGINT UNSIGNED NOT NULL,
message_text TEXT NOT NULL,
is_read TINYINT(1) NOT NULL DEFAULT 0,
created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
CONSTRAINT fk_messages_thread
FOREIGN KEY (thread_id) REFERENCES message_threads(id)
ON DELETE CASCADE,
CONSTRAINT fk_messages_sender
FOREIGN KEY (sender_id) REFERENCES users(id)
ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE support_tickets (
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
farmer_id BIGINT UNSIGNED NOT NULL,
message_text TEXT NOT NULL,
status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
CONSTRAINT fk_support_farmer
FOREIGN KEY (farmer_id) REFERENCES users(id)
ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_products_farmer ON products(farmer_id);
CREATE INDEX idx_products_category ON products(category_id);
CREATE INDEX idx_orders_farmer_status ON orders(farmer_id, status);
CREATE INDEX idx_orders_consumer ON orders(consumer_id);
CREATE INDEX idx_order_items_order ON order_items(order_id);
CREATE INDEX idx_threads_farmer ON message_threads(farmer_id);
CREATE INDEX idx_messages_thread_created ON messages(thread_id, created_at);
CREATE INDEX idx_support_farmer_status ON support_tickets(farmer_id, status);

/*
USEFUL FARMER DASHBOARD QUERIES

Total customers for a farmer:
SELECT COUNT(DISTINCT consumer_id) AS total_customers
FROM orders
WHERE farmer_id = :farmer_id;

Sold items this month:
SELECT COALESCE(SUM(oi.qty), 0) AS sold_items
FROM orders o
JOIN order_items oi ON oi.order_id = o.id
WHERE o.farmer_id = :farmer_id
AND o.status = 'completed'
AND DATE_FORMAT(o.placed_at, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m');

Unread messages count:
SELECT COUNT(*) AS unread_messages
FROM messages m
JOIN message_threads t ON t.id = m.thread_id
WHERE t.farmer_id = :farmer_id
AND m.sender_id <> :farmer_id
AND m.is_read = 0;

Top products:
SELECT
oi.product_name_snapshot AS product_name,
SUM(oi.qty) AS total_qty
FROM orders o
JOIN order_items oi ON oi.order_id = o.id
WHERE o.farmer_id = :farmer_id
AND o.status IN ('completed','to_receive')
GROUP BY oi.product_name_snapshot
ORDER BY total_qty DESC
LIMIT 10;

Latest orders:
SELECT
o.order_code,
o.status,
o.payment_status,
o.payment_method,
o.total_amount,
o.placed_at
FROM orders o
WHERE o.farmer_id = :farmer_id
ORDER BY o.placed_at DESC
LIMIT 20;
*/