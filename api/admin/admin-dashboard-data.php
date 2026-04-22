<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'Please login first',
    ]);
    exit;
}

try {
    $userStmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute([':id' => $userId]);
    $user = $userStmt->fetch();

    if (!$user || (int) ($user['is_active'] ?? 0) !== 1) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'message' => 'User not found or inactive',
        ]);
        exit;
    }

    $role = normalizeRole((string) ($user['role'] ?? ''));
    if ($role !== 'admin') {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'message' => 'Only admins can access this dashboard',
        ]);
        exit;
    }

    $statsStmt = $pdo->query(
        'SELECT
            (SELECT COUNT(*) FROM users) AS total_users,
            (SELECT COUNT(*) FROM users WHERE LOWER(role) = "farmer") AS total_farmers,
            (SELECT COUNT(*) FROM users WHERE LOWER(role) IN ("consumer", "customer")) AS total_customers,
            (SELECT COUNT(*) FROM users WHERE is_active = 1) AS active_users,
            (SELECT COUNT(*) FROM users WHERE LOWER(role) = "farmer" AND is_active = 1) AS active_farmers,
            (SELECT COUNT(*) FROM users WHERE LOWER(role) IN ("consumer", "customer") AND is_active = 1) AS active_customers,
            (SELECT COUNT(*) FROM products) AS total_products,
            (SELECT COUNT(*) FROM products WHERE is_active = 1) AS active_products,
            (SELECT COUNT(*) FROM orders) AS total_orders,
            (SELECT COUNT(*) FROM orders WHERE status = "pending") AS pending_orders,
            (SELECT COUNT(*) FROM orders WHERE status = "to_receive") AS to_receive_orders,
            (SELECT COUNT(*) FROM orders WHERE status = "completed") AS completed_orders,
            (SELECT COUNT(*) FROM orders WHERE status = "refund_return") AS refund_orders,
            (SELECT COUNT(*) FROM orders WHERE status = "cancelled") AS cancelled_orders,
            (SELECT COALESCE(SUM(CASE WHEN status = "completed" THEN total_amount ELSE 0 END), 0) FROM orders) AS total_revenue,
            (SELECT COUNT(*) FROM messages WHERE is_read = 0) AS unread_messages,
            (SELECT COUNT(*) FROM support_tickets WHERE status = "open") AS open_tickets,
            (SELECT COUNT(*) FROM support_tickets WHERE status = "in_progress") AS in_progress_tickets,
            (SELECT COUNT(*) FROM support_tickets WHERE status = "resolved") AS resolved_tickets,
            (SELECT COUNT(*) FROM support_tickets WHERE status = "closed") AS closed_tickets'
    );
    $stats = $statsStmt ? ($statsStmt->fetch() ?: []) : [];

    $recentFarmersStmt = $pdo->prepare(
        'SELECT
            u.id,
            u.full_name,
            u.email,
            u.phone,
            u.profile_image,
            u.division,
            u.district,
            u.is_active,
            u.created_at,
            COALESCE((SELECT COUNT(*) FROM products p WHERE p.farmer_id = u.id), 0) AS product_count,
            COALESCE((SELECT COUNT(*) FROM orders o WHERE o.farmer_id = u.id), 0) AS order_count,
            COALESCE((SELECT SUM(o.total_amount) FROM orders o WHERE o.farmer_id = u.id AND o.status = "completed"), 0) AS revenue
         FROM users u
         WHERE LOWER(u.role) = "farmer"
         ORDER BY u.created_at DESC, u.id DESC
         LIMIT 8'
    );
    $recentFarmersStmt->execute();

    $recentCustomersStmt = $pdo->prepare(
        'SELECT
            u.id,
            u.full_name,
            u.email,
            u.phone,
            u.profile_image,
            u.division,
            u.district,
            u.is_active,
            u.created_at,
            COALESCE((SELECT COUNT(*) FROM orders o WHERE o.consumer_id = u.id), 0) AS order_count,
            COALESCE((SELECT SUM(o.total_amount) FROM orders o WHERE o.consumer_id = u.id AND o.status = "completed"), 0) AS spent,
            (SELECT MAX(o.placed_at) FROM orders o WHERE o.consumer_id = u.id) AS last_order_at
         FROM users u
         WHERE LOWER(u.role) IN ("consumer", "customer")
         ORDER BY u.created_at DESC, u.id DESC
         LIMIT 8'
    );
    $recentCustomersStmt->execute();

    $recentOrdersStmt = $pdo->prepare(
        'SELECT
            o.id,
            o.order_code,
            o.status,
            o.payment_status,
            o.payment_method,
            o.total_amount,
            o.placed_at,
            c.full_name AS customer_name,
            f.full_name AS farmer_name
         FROM orders o
         INNER JOIN users c ON c.id = o.consumer_id
         INNER JOIN users f ON f.id = o.farmer_id
         ORDER BY o.placed_at DESC, o.id DESC
         LIMIT 10'
    );
    $recentOrdersStmt->execute();

    $topProductsStmt = $pdo->prepare(
        'SELECT
            p.id,
            p.name,
            p.image_path,
            p.stock_qty,
            p.is_active,
            u.full_name AS farmer_name,
            COALESCE(ROUND(AVG(r.rating), 2), 0) AS rating_avg,
            COUNT(r.id) AS rating_count,
            COALESCE(SUM(CASE WHEN o.status = "completed" THEN oi.qty ELSE 0 END), 0) AS sold_qty
         FROM products p
         INNER JOIN users u ON u.id = p.farmer_id
         LEFT JOIN product_ratings r ON r.product_id = p.id
         LEFT JOIN order_items oi ON oi.product_id = p.id
         LEFT JOIN orders o ON o.id = oi.order_id
         GROUP BY p.id, p.name, p.image_path, p.stock_qty, p.is_active, u.full_name
         ORDER BY sold_qty DESC, p.created_at DESC
         LIMIT 6'
    );
    $topProductsStmt->execute();

    $supportTicketsStmt = $pdo->prepare(
        'SELECT
            st.id,
            st.status,
            st.message_text,
            st.created_at,
            u.full_name AS farmer_name,
            u.district
         FROM support_tickets st
         INNER JOIN users u ON u.id = st.farmer_id
         ORDER BY st.created_at DESC, st.id DESC
         LIMIT 8'
    );
    $supportTicketsStmt->execute();

    echo json_encode([
        'ok' => true,
        'stats' => [
            'total_users' => (int) ($stats['total_users'] ?? 0),
            'total_farmers' => (int) ($stats['total_farmers'] ?? 0),
            'total_customers' => (int) ($stats['total_customers'] ?? 0),
            'active_users' => (int) ($stats['active_users'] ?? 0),
            'active_farmers' => (int) ($stats['active_farmers'] ?? 0),
            'active_customers' => (int) ($stats['active_customers'] ?? 0),
            'total_products' => (int) ($stats['total_products'] ?? 0),
            'active_products' => (int) ($stats['active_products'] ?? 0),
            'total_orders' => (int) ($stats['total_orders'] ?? 0),
            'pending_orders' => (int) ($stats['pending_orders'] ?? 0),
            'to_receive_orders' => (int) ($stats['to_receive_orders'] ?? 0),
            'completed_orders' => (int) ($stats['completed_orders'] ?? 0),
            'refund_orders' => (int) ($stats['refund_orders'] ?? 0),
            'cancelled_orders' => (int) ($stats['cancelled_orders'] ?? 0),
            'total_revenue' => (float) ($stats['total_revenue'] ?? 0),
            'unread_messages' => (int) ($stats['unread_messages'] ?? 0),
            'open_tickets' => (int) ($stats['open_tickets'] ?? 0),
            'in_progress_tickets' => (int) ($stats['in_progress_tickets'] ?? 0),
            'resolved_tickets' => (int) ($stats['resolved_tickets'] ?? 0),
            'closed_tickets' => (int) ($stats['closed_tickets'] ?? 0),
        ],
        'recent_farmers' => formatUserRows($recentFarmersStmt->fetchAll() ?: [], 'farmer'),
        'recent_customers' => formatUserRows($recentCustomersStmt->fetchAll() ?: [], 'customer'),
        'recent_orders' => formatOrders($recentOrdersStmt->fetchAll() ?: []),
        'top_products' => formatProducts($topProductsStmt->fetchAll() ?: []),
        'support_tickets' => formatTickets($supportTicketsStmt->fetchAll() ?: []),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Failed to load admin dashboard data',
    ]);
}

function normalizeRole(string $role): string
{
    $value = strtolower(trim($role));
    if ($value === 'customer') {
        $value = 'consumer';
    }

    return $value;
}

function toPublicAssetPath(string $rawPath): string
{
    $path = trim(str_replace('\\', '/', $rawPath));
    if ($path === '') {
        $path = '/figma/images (2).jpg';
    }

    if (!preg_match('#^(https?:)?//#i', $path) && strpos($path, 'data:') !== 0) {
        if ($path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        $basePath = trim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''), 3)), '/');
        if ($basePath !== '') {
            $prefix = '/' . $basePath;
            if (strpos($path, $prefix . '/') !== 0 && $path !== $prefix) {
                $path = $prefix . $path;
            }
        }
    }

    return $path;
}

function formatUserRows(array $rows, string $kind): array
{
    $formatted = [];
    foreach ($rows as $row) {
        $isActive = (int) ($row['is_active'] ?? 0) === 1;
        $formatted[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['full_name'] ?? ($kind === 'farmer' ? 'Farmer' : 'Customer')),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'profile_image' => toPublicAssetPath((string) ($row['profile_image'] ?? '')),
            'division' => (string) ($row['division'] ?? ''),
            'district' => (string) ($row['district'] ?? ''),
            'is_active' => $isActive,
            'status_label' => $isActive ? 'Active' : 'Inactive',
            'created_at' => (string) ($row['created_at'] ?? ''),
            'product_count' => (int) ($row['product_count'] ?? 0),
            'order_count' => (int) ($row['order_count'] ?? 0),
            'revenue' => (float) ($row['revenue'] ?? 0),
            'spent' => (float) ($row['spent'] ?? 0),
            'last_order_at' => (string) ($row['last_order_at'] ?? ''),
        ];
    }

    return $formatted;
}

function formatOrders(array $rows): array
{
    $formatted = [];
    foreach ($rows as $row) {
        $statusRaw = strtolower((string) ($row['status'] ?? 'pending'));
        $statusLabel = ucfirst(str_replace('_', ' ', $statusRaw));
        $statusClass = 'status-pending';

        if ($statusRaw === 'completed') {
            $statusClass = 'status-completed';
        } elseif ($statusRaw === 'cancelled') {
            $statusClass = 'status-cancelled';
        } elseif ($statusRaw === 'refund_return') {
            $statusClass = 'status-refund';
        } elseif ($statusRaw === 'to_receive') {
            $statusClass = 'status-receive';
        }

        $formatted[] = [
            'id' => (int) ($row['id'] ?? 0),
            'order_code' => (string) ($row['order_code'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? 'Customer'),
            'farmer_name' => (string) ($row['farmer_name'] ?? 'Farmer'),
            'payment_status' => ucwords(str_replace('_', ' ', strtolower((string) ($row['payment_status'] ?? 'pending')))),
            'payment_method' => ucwords(str_replace('_', ' ', strtolower((string) ($row['payment_method'] ?? 'cash')))),
            'amount' => (float) ($row['total_amount'] ?? 0),
            'placed_at' => (string) ($row['placed_at'] ?? ''),
            'status_label' => $statusLabel,
            'status_class' => $statusClass,
        ];
    }

    return $formatted;
}

function formatProducts(array $rows): array
{
    $formatted = [];
    foreach ($rows as $row) {
        $formatted[] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? 'Product'),
            'image_path' => toPublicAssetPath((string) ($row['image_path'] ?? '')),
            'farmer_name' => (string) ($row['farmer_name'] ?? 'Farmer'),
            'stock_qty' => (int) ($row['stock_qty'] ?? 0),
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
            'sold_qty' => (float) ($row['sold_qty'] ?? 0),
            'rating_avg' => (float) ($row['rating_avg'] ?? 0),
            'rating_count' => (int) ($row['rating_count'] ?? 0),
        ];
    }

    return $formatted;
}

function formatTickets(array $rows): array
{
    $formatted = [];
    foreach ($rows as $row) {
        $statusRaw = strtolower((string) ($row['status'] ?? 'open'));
        $statusLabel = ucwords(str_replace('_', ' ', $statusRaw));
        $statusClass = 'ticket-open';

        if ($statusRaw === 'in_progress') {
            $statusClass = 'ticket-progress';
        } elseif ($statusRaw === 'resolved') {
            $statusClass = 'ticket-resolved';
        } elseif ($statusRaw === 'closed') {
            $statusClass = 'ticket-closed';
        }

        $formatted[] = [
            'id' => (int) ($row['id'] ?? 0),
            'farmer_name' => (string) ($row['farmer_name'] ?? 'Farmer'),
            'district' => (string) ($row['district'] ?? ''),
            'message_text' => (string) ($row['message_text'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'status_label' => $statusLabel,
            'status_class' => $statusClass,
        ];
    }

    return $formatted;
}
