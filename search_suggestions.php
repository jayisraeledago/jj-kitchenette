<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

$query = trim($_GET['q'] ?? '');

if ($query === '') {
    echo json_encode(['items' => []]);
    exit;
}

$searchTerm = '%' . $query . '%';

$stmt = $conn->prepare("
    SELECT
        p.handle,
        p.title,
        c.name AS category_name,
        COALESCE(MIN(v.price), 0) AS min_price,
        (
            SELECT pi.image_path
            FROM product_images pi
            WHERE pi.product_id = p.id
            ORDER BY pi.is_main DESC, pi.sort_order ASC
            LIMIT 1
        ) AS image_path
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN product_variants v ON v.product_id = p.id
    WHERE p.status = 'active'
      AND (
          p.title LIKE ?
          OR p.body LIKE ?
          OR c.name LIKE ?
          OR EXISTS (
              SELECT 1
              FROM product_variants sv
              WHERE sv.product_id = p.id
                AND sv.sku LIKE ?
          )
      )
    GROUP BY p.id, p.handle, p.title, c.name
    ORDER BY p.title ASC
    LIMIT 4
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['items' => []]);
    exit;
}

$stmt->bind_param('ssss', $searchTerm, $searchTerm, $searchTerm, $searchTerm);
$stmt->execute();
$result = $stmt->get_result();

$items = [];
while ($row = $result->fetch_assoc()) {
    $imagePath = !empty($row['image_path']) ? $row['image_path'] : 'uploads/default.png';

    $items[] = [
        'title' => $row['title'],
        'category' => $row['category_name'] ?: 'Menu',
        'price' => '₱' . number_format((float) $row['min_price'], 2),
        'image' => '/jj_kitchenette/' . ltrim($imagePath, '/'),
        'url' => '/jj_kitchenette/product.php?handle=' . urlencode($row['handle']),
    ];
}

echo json_encode(['items' => $items]);
