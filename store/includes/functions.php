<?php
function resolveProductCategoryId($conn, $category_id, $new_category_name)
{
    if ($category_id !== '__new__') {
        return (int) $category_id;
    }

    $new_category_name = trim($new_category_name);

    if ($new_category_name === '') {
        return 0;
    }

    $check = $conn->prepare("
        SELECT id
        FROM categories
        WHERE LOWER(name) = LOWER(?)
        LIMIT 1
    ");

    $check->bind_param("s", $new_category_name);
    $check->execute();

    $result = $check->get_result();
    $category = $result->fetch_assoc();

    if ($category) {
        return (int) $category['id'];
    }

    $insert = $conn->prepare("
        INSERT INTO categories (name)
        VALUES (?)
    ");

    $insert->bind_param("s", $new_category_name);
    $insert->execute();

    return (int) $conn->insert_id;
}

function generateSkuFromTitle($title, $suffix = '')
{
    $sku = strtoupper(trim($title));
    $sku = preg_replace('/[^A-Z0-9]+/', '-', $sku);
    $sku = trim($sku, '-');

    if ($sku === '') {
        $sku = 'PRODUCT';
    }

    $suffix = trim((string) $suffix);

    if ($suffix !== '') {
        $suffix = '-' . strtoupper($suffix);
    }

    $maxBaseLength = max(1, 10 - strlen($suffix));

    return substr($sku, 0, $maxBaseLength) . $suffix;
}

function uploadProductImages($conn, $product_id)
{
    $uploaded = false;

    if (empty($_FILES['images']['name'][0])) {
        mysqli_query($conn, "
            INSERT INTO product_images (product_id, image_path, is_main, sort_order)
            SELECT '$product_id', 'uploads/default.png', 1, 0
            WHERE NOT EXISTS (
                SELECT 1 FROM product_images WHERE product_id='$product_id'
            )
        ");

        return;
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $uploadDir = dirname(__DIR__, 2) . "/uploads/";

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // ✅ GET CURRENT MAX ORDER (THIS IS THE KEY FIX)
    $res = mysqli_query($conn, "
        SELECT MAX(sort_order) as max_order 
        FROM product_images 
        WHERE product_id='$product_id'
    ");

    $row = mysqli_fetch_assoc($res);
    $currentOrder = ($row['max_order'] !== null) ? (int) $row['max_order'] : -1;

    $mainAssigned = false;

    foreach ($_FILES['images']['tmp_name'] as $key => $tmpName) {

        if ($_FILES['images']['error'][$key] !== 0)
            continue;
        if ($_FILES['images']['size'][$key] > 5 * 1024 * 1024)
            continue;

        $name = $_FILES['images']['name'][$key];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed))
            continue;

        $imageInfo = @getimagesize($tmpName);
        if ($imageInfo === false)
            continue;

        $cleanName = preg_replace('/[^a-zA-Z0-9.]/', '_', $name);
        $imageName = bin2hex(random_bytes(8)) . "_" . $cleanName;

        $target = $uploadDir . $imageName;

        if (move_uploaded_file($tmpName, $target)) {

            $imagePath = "uploads/" . $imageName;

            $currentOrder++; // ✅ APPEND TO END

            // OPTIONAL: first new image becomes main if none exists
            $isMain = 0;

            mysqli_query($conn, "
                INSERT INTO product_images 
                (product_id, image_path, is_main, sort_order)
                VALUES 
                ('$product_id', '$imagePath', '$isMain', '$currentOrder')
            ");

            $uploaded = true;
        }
    }

    // ✅ ONLY SET MAIN IF NONE EXISTS
    $checkMain = mysqli_query($conn, "
        SELECT id FROM product_images 
        WHERE product_id='$product_id' AND is_main=1
        LIMIT 1
    ");

    if (mysqli_num_rows($checkMain) === 0) {
        mysqli_query($conn, "
            UPDATE product_images 
            SET is_main = 1 
            WHERE product_id = '$product_id'
            ORDER BY sort_order ASC 
            LIMIT 1
        ");
    }

    // ✅ ensure at least 1 image exists
    $check = mysqli_query($conn, "
        SELECT id FROM product_images WHERE product_id='$product_id' LIMIT 1
    ");

    if (mysqli_num_rows($check) === 0) {
        mysqli_query($conn, "
        INSERT INTO product_images (product_id, image_path, is_main, sort_order)
        VALUES ('$product_id', 'uploads/default.png', 1, 0)
    ");
    }
}
