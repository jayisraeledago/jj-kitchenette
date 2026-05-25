<?php
include __DIR__ . '/../db.php';
include __DIR__ . '/includes/functions.php';
include __DIR__ . '/includes/admin-auth.php';
requireAdminPermission($conn, ['products', 'inventory']);

$id = $_GET['id'] ?? 0;

$product = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT * FROM products WHERE id='$id'
"));

$productImages = mysqli_query($conn, "
    SELECT * FROM product_images 
    WHERE product_id='$id'
    ORDER BY sort_order ASC
");

$variantsCheck = mysqli_query($conn, "
    SELECT * FROM product_variants WHERE product_id='$id'
");

$variantRows = [];
while ($row = mysqli_fetch_assoc($variantsCheck)) {
    $variantRows[] = $row;
}

$isDefaultOnly = false;

if (count($variantRows) === 1 && strtolower(trim($variantRows[0]['option1_value'])) === 'default') {
    $isDefaultOnly = true;
}

if (!$product) {
    die("Product not found");
}

function createHandle($title)
{
    $handle = strtolower($title);
    $handle = preg_replace('/[^a-z0-9\s-]/', '', $handle);
    $handle = preg_replace('/\s+/', '-', $handle);
    $handle = preg_replace('/-+/', '-', $handle);
    return trim($handle, '-');
}

function rejectInvalidProductNumber($values, $label, $integerOnly = false)
{
    if (!is_array($values)) {
        $values = [$values];
    }

    foreach ($values as $value) {
        if ($value === '' || $value === null) {
            continue;
        }

        if (
            !is_numeric($value)
            || (float) $value < 0
            || ($integerOnly && !preg_match('/^\d+$/', trim((string) $value)))
        ) {
            echo "<script>alert('$label must be a non-negative" . ($integerOnly ? " whole" : "") . " number'); window.history.back();</script>";
            exit;
        }
    }
}

if (isset($_POST['delete'])) {

    $product_id = $id;

    // =========================
    // DELETE PRODUCT IMAGES (FILES + DB)
    // =========================
    $images = mysqli_query($conn, "
        SELECT image_path FROM product_images WHERE product_id='$product_id'
    ");

    while ($img = mysqli_fetch_assoc($images)) {
        $file = $img['image_path'];

        if ($file !== 'default.png' && file_exists($file)) {
            unlink($file);
        }
    }

    mysqli_query($conn, "DELETE FROM product_images WHERE product_id='$product_id'");

    // =========================
    // DELETE VARIANTS
    // =========================
    mysqli_query($conn, "DELETE FROM product_variants WHERE product_id='$product_id'");

    // =========================
    // DELETE OPTION VALUES FIRST
    // =========================
    $options = mysqli_query($conn, "
        SELECT id FROM product_options WHERE product_id='$product_id'
    ");

    while ($opt = mysqli_fetch_assoc($options)) {
        mysqli_query($conn, "
            DELETE FROM product_option_values WHERE option_id='{$opt['id']}'
        ");
    }

    // DELETE OPTIONS
    mysqli_query($conn, "DELETE FROM product_options WHERE product_id='$product_id'");

    // =========================
    // DELETE PRODUCT
    // =========================
    mysqli_query($conn, "DELETE FROM products WHERE id='$product_id'");

    // REDIRECT
    header("Location: products.php");
    exit;
}

if (isset($_POST['save'])) {

    // BASIC INFO
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $category_id = resolveProductCategoryId(
        $conn,
        $_POST['category_id'] ?? '',
        $_POST['new_category_name'] ?? ''
    );
    $status = $_POST['status'] ?? '';


    // ✅ VALIDATION (PUT IT HERE)
    if (empty($title)) {
        echo "<script>alert('Product title is required'); window.history.back();</script>";
        exit;
    }

    if (empty($category_id)) {
        echo "<script>alert('Please select a category'); window.history.back();</script>";
        exit;
    }

    if (empty($status)) {
        echo "<script>alert('Please select a status'); window.history.back();</script>";
        exit;
    }


    // HANDLE
    $handle = createHandle($title);

    $check = mysqli_query($conn, "
        SELECT id FROM products 
        WHERE handle='$handle' AND id != '$id'
    ");

    // INSERT PRODUCT
    $stmt = $conn->prepare("
        UPDATE products 
        SET title=?, body=?, category_id=?, status=?
        WHERE id=?
    ");

    $stmt->bind_param("ssisi", $title, $description, $category_id, $status, $id);
    $stmt->execute();

    if ($stmt->error) {
        die("Error inserting product: " . $stmt->error);
    }

    $product_id = $id;


    if (!empty($_POST['delete_images'])) {

        $deleteIds = explode(',', $_POST['delete_images']);

        foreach ($deleteIds as $img_id) {

            // get file path
            $res = mysqli_query($conn, "
            SELECT image_path FROM product_images WHERE id='$img_id'
        ");

            if ($row = mysqli_fetch_assoc($res)) {

                $file = $row['image_path'];

                // delete file
                if ($file && file_exists($file)) {
                    unlink($file);
                }
            }

            // delete from DB
            mysqli_query($conn, "
            DELETE FROM product_images WHERE id='$img_id'
        ");
        }
    }

    // =========================
// SAVE IMAGES FIRST ✅
// =========================
    if (!empty($_FILES['images']['name'][0])) {
        uploadProductImages($conn, $product_id);
    }


    // ✅ ADD HERE (SORT ORDER)
    if (!empty($_POST['image_order'])) {
        $order = explode(',', $_POST['image_order']);

        foreach ($order as $position => $image_id) {
            mysqli_query($conn, "
            UPDATE product_images 
            SET sort_order = '$position'
            WHERE id = '$image_id'
        ");
        }
    }

    // ✅ SET MAIN IMAGE
    if (isset($_POST['main_image'])) {

        $mainIndex = intval($_POST['main_image']);

        mysqli_query($conn, "
        UPDATE product_images 
        SET is_main = 0 
        WHERE product_id = '$product_id'
    ");

        $images = mysqli_query($conn, "
        SELECT id FROM product_images 
        WHERE product_id = '$product_id'
        ORDER BY sort_order ASC
    ");

        $i = 0;
        while ($img = mysqli_fetch_assoc($images)) {

            if ($i == $mainIndex) {
                mysqli_query($conn, "
                UPDATE product_images 
                SET is_main = 1 
                WHERE id = '{$img['id']}'
            ");
                break;
            }
            $i++;
        }
    }

    if (!empty($_POST['image_order'])) {

        $order = explode(',', $_POST['image_order']);

        foreach ($order as $position => $image_id) {

            mysqli_query($conn, "
            UPDATE product_images 
            SET sort_order = '$position'
            WHERE id = '$image_id'
        ");
        }
    }

    // =========================
    // GET OPTIONS + VALUES
    // =========================
    $option1 = array_map(fn($v) => mysqli_real_escape_string($conn, trim($v)), $_POST['option1_value'] ?? []);
    $option2 = array_map(fn($v) => mysqli_real_escape_string($conn, trim($v)), $_POST['option2_value'] ?? []);
    $option3 = array_map(fn($v) => mysqli_real_escape_string($conn, trim($v)), $_POST['option3_value'] ?? []);
    $skus = array_map(fn($v) => mysqli_real_escape_string($conn, trim($v)), $_POST['sku'] ?? []);

    $option1_name = mysqli_real_escape_string($conn, $_POST['option_name'] ?? '');
    $option2_name = mysqli_real_escape_string($conn, $_POST['option2_name'] ?? '');
    $option3_name = mysqli_real_escape_string($conn, $_POST['option3_name'] ?? '');

    $prices = $_POST['price'] ?? [];
    $stocks = $_POST['inventory'] ?? [];

    // ensure array
    if (!is_array($prices)) {
        $prices = [$prices];
    }
    if (!is_array($stocks)) {
        $stocks = [$stocks];
    }

    rejectInvalidProductNumber($prices, 'Price');
    rejectInvalidProductNumber($stocks, 'Stock', true);

    // sanitize
    $prices = array_map('floatval', $prices);
    $stocks = array_map('intval', $stocks);
    // =========================
    // SAVE OPTIONS FUNCTION
    // =========================
    function saveOption($conn, $product_id, $name, $values)
    {
        $values = array_unique(array_filter($values)); // remove empty values

        if (!empty($name) && !empty($values)) {
            mysqli_query($conn, "
                INSERT INTO product_options (product_id, option_name)
                VALUES ('$product_id', '$name')
            ");

            $option_id = mysqli_insert_id($conn);

            foreach ($values as $val) {
                mysqli_query($conn, "
                    INSERT INTO product_option_values (option_id, value)
                    VALUES ('$option_id', '$val')
                ");
            }
        }
    }

    // =========================
// DELETE OLD DATA FIRST
// =========================
    mysqli_query($conn, "DELETE FROM product_variants WHERE product_id='$id'");
    mysqli_query($conn, "DELETE FROM product_options WHERE product_id='$id'");

    // =========================
// CHECK IF REAL VARIANTS
// =========================
    $hasRealVariants = false;

    foreach ($option1 as $val) {
        if (!empty(trim($val)) && strtolower(trim($val)) !== 'default') {
            $hasRealVariants = true;
            break;
        }
    }

    // =========================
// INSERT VARIANTS
// =========================
    $reservedSkus = [];

    if ($hasRealVariants) {

        saveOption($conn, $product_id, $option1_name, $option1);
        saveOption($conn, $product_id, $option2_name, $option2);
        saveOption($conn, $product_id, $option3_name, $option3);

        $total = count($prices);

        for ($i = 0; $i < $total; $i++) {

            $opt1 = $option1[$i] ?? '';
            $opt2 = isset($option2[$i]) && $option2[$i] !== '' ? $option2[$i] : NULL;
            $opt3 = isset($option3[$i]) && $option3[$i] !== '' ? $option3[$i] : NULL;

            $opt2_sql = $opt2 === NULL ? "NULL" : "'$opt2'";
            $opt3_sql = $opt3 === NULL ? "NULL" : "'$opt3'";

            $price = $prices[$i] ?? 0;
            $stock = $stocks[$i] ?? 0;
            $sku = strtoupper(str_replace(' ', '', $skus[$i] ?? ''));

            if (empty($sku)) {
                $sku = generateSkuFromTitle($title, $i + 1);
            }
            $sku = ensureUniqueVariantSku($conn, $sku, $reservedSkus, (int) $product_id);

            mysqli_query($conn, "
            INSERT INTO product_variants 
            (product_id, option1_value, option2_value, option3_value, price, inventory, sku)
            VALUES 
            ('$product_id', '$opt1', $opt2_sql, $opt3_sql, '$price', '$stock', '$sku')
        ");
        }

    } else {

        // =========================
        // SIMPLE PRODUCT (DEFAULT)
        // =========================
        $base_price = floatval($_POST['price'] ?? 0);
        $base_stock = intval($_POST['inventory'] ?? 0); // ✅ ADD THIS
        $base_sku = mysqli_real_escape_string($conn, $_POST['base_sku'] ?? '');

        if (empty($base_sku)) {
            $base_sku = generateSkuFromTitle($title);
        }
        $base_sku = ensureUniqueVariantSku($conn, $base_sku, $reservedSkus, (int) $product_id);

        mysqli_query($conn, "
        INSERT INTO product_variants 
        (product_id, option1_value, price, inventory, sku)
        VALUES 
        ('$product_id', 'Default', '$base_price', '$base_stock', '$base_sku')
    ");
    }

    // REDIRECT
    header("Location: products.php");
    exit;
}
?>

<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product | J&J's Kitchenette Admin</title>
    <link rel="icon" type="image/png" href="/assets/images/favicon.png">
    <link rel="shortcut icon" type="image/png" href="/assets/images/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>

<body>

    <div class="app">

        <?php include 'includes/sidebar.php'; ?>

        <div class="main">

            <div class="page-container">
                <div class="product-editor-top">
                    <a href="products.php" class="product-editor-back">
                        <i class="fa-solid fa-arrow-left"></i>
                        Back to products
                    </a>

                    <div class="product-editor-heading">
                        <div>
                            <h1>Edit Product</h1>
                            <p>Update your product details and inventory.</p>
                        </div>

                        <div class="product-editor-actions">
                            <a href="../product.php?handle=<?= urlencode($product['handle']) ?>" target="_blank">
                                <i class="fa-regular fa-eye"></i>
                                View Product
                            </a>
                            <button type="submit" form="productForm" name="save">
                                <i class="fa-regular fa-floppy-disk"></i>
                                Save Changes
                            </button>
                        </div>
                    </div>
                </div>

                <div class="layout product-editor-layout">

                    <!-- LEFT -->
                    <div class="left">
                        <div class="product-editor-card">
                            <form id="productForm" class="product-editor-form product-editor-form--legacy" method="POST" enctype="multipart/form-data">

                                <!-- TITLE -->
                                <label>Title</label>
                                <input type="text" name="title" value="<?= htmlspecialchars($product['title']) ?>"
                                    required>

                                <!-- DESCRIPTION -->
                                <label>Description</label>
                                <textarea name="description"><?= htmlspecialchars($product['body']) ?></textarea>

                                <label>Product Image</label>
                                <input type="file" name="images[]" id="imageInput" accept="image/*" multiple>
                                <input type="hidden" name="image_order" id="imageOrder">
                                <input type="hidden" name="main_image" id="mainImage">
                                <input type="hidden" name="delete_images" id="deleteImages">
                                <div id="preview">

                                    <?php while ($img = mysqli_fetch_assoc($productImages)): ?>
                                        <div class="image-item" data-id="<?= $img['id'] ?>">
                                            <img src="../<?= $img['image_path'] ?>">

                                            <button type="button" class="delete-img-btn">✕</button>
                                        </div>
                                    <?php endwhile; ?>

                                </div>

                                <label>Category</label>
                                <select name="category_id" class="category-select" required>
                                    <option value="">Select Category</option>

                                    <?php
                                    $cat = mysqli_query($conn, "SELECT * FROM categories");
                                    while ($c = mysqli_fetch_assoc($cat)):
                                        ?>
                                        <option value="<?= $c['id'] ?>" <?= $c['id'] == $product['category_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                    <option value="__new__">+ Add new category</option>
                                </select>

                                <div class="new-category-field" style="display:none;">
                                    <label>New Category</label>
                                    <input type="text" name="new_category_name" placeholder="Category name">
                                </div>

                                <label>Status</label>
                                <select name="status" required>
                                    <option value="active" <?= $product['status'] == 'active' ? 'selected' : '' ?>>Active
                                    </option>
                                    <option value="draft" <?= $product['status'] == 'draft' ? 'selected' : '' ?>>Draft
                                    </option>
                                </select>

                                <div id="base-price-section" style="<?= $isDefaultOnly ? '' : 'display:none;' ?>">

                                    <div class="three-cols">

                                        <div class="field">
                                            <label>Price</label>
                                            <input type="number" name="price" min="0" step="0.01"
                                                value="<?= $isDefaultOnly ? $variantRows[0]['price'] : '' ?>"
                                                placeholder="Base Price">
                                        </div>

                                        <div class="field">
                                            <label>SKU</label>
                                            <input type="text" name="base_sku"
                                                value="<?= $isDefaultOnly ? $variantRows[0]['sku'] : '' ?>"
                                                placeholder="Product SKU">
                                        </div>

                                        <div class="field">
                                            <label>Stock</label>
                                            <input type="number" name="inventory" min="0" step="1"
                                                value="<?= $isDefaultOnly ? $variantRows[0]['inventory'] : '' ?>"
                                                placeholder="Available Stock">
                                        </div>

                                    </div>

                                </div>

                                <!-- VARIANTS -->
                                <?php if (!$isDefaultOnly): ?>
                                    <!-- VARIANTS UI START -->
                                    <h3>Variants</h3>
                                    <!-- ALL your variant form, buttons, list, etc -->
                                <?php endif; ?>

                                <!-- OPTION FORM -->
                                <div id="variant-form" style="display:none; margin-top:15px;">

                                    <!-- OPTION 1 -->
                                    <label>Option Name</label>
                                    <input type="text" id="option-name" name="option_name"
                                        placeholder="e.g. Size or Color">

                                    <label>Option Values</label>
                                    <input type="text" id="option-values" placeholder="e.g. Small, Medium, Large">

                                    <!-- OPTION 2 -->
                                    <div id="option2-wrapper" style="display:none;">
                                        <label>Option 2 Name</label>
                                        <input type="text" id="option2-name" name="option2_name"
                                            placeholder="e.g. Size">

                                        <label>Option 2 Values</label>
                                        <input type="text" id="option2-values" placeholder="e.g. Small, Large">
                                    </div>

                                    <!-- OPTION 3 -->
                                    <div id="option3-wrapper" style="display:none;">
                                        <label>Option 3 Name</label>
                                        <input type="text" id="option3-name" name="option3_name"
                                            placeholder="e.g. Add-ons">

                                        <label>Option 3 Values</label>
                                        <input type="text" id="option3-values" placeholder="e.g. Cheese, Drink">
                                    </div>

                                </div>

                                <div class="product-editor-inline-actions">
                                    <button type="button" id="add-variant-btn">+ Add Variant</button>
                                    <button
                                        type="button"
                                        id="reset-variants"
                                        style="<?= $isDefaultOnly ? 'display:none;' : 'display:inline-block;' ?>"
                                    >
                                        Remove Variants
                                    </button>
                                    <button type="button" id="generate-btn" style="display:none;">Generate Variants</button>
                                </div>

                                <div class="variant-header" style="visibility: hidden;">
                                    <span>Option</span>
                                    <span>SKU</span>
                                    <span>Price</span>
                                    <span>Stock</span>
                                </div>

                                <div id="variant-list" style="margin-top:15px;"></div>

                                <div class="product-form-actions product-form-actions--edit">
                                    <button type="submit" name="save">
                                        <i class="fa-regular fa-floppy-disk"></i>
                                        Save Product
                                    </button>
                                    <button type="button" id="openDeleteModal" class="product-danger-button">
                                        <i class="fa-regular fa-trash-can"></i>
                                        Delete Product
                                    </button>
                                </div>
                            </form>

                            <!-- DELETE MODAL -->
                            <div id="deleteModal" class="modal">
                                <div class="modal-content">
                                    <h3>Delete Product</h3>
                                    <p>Are you sure you want to delete this product?</p>

                                    <div class="modal-actions">
                                        <button id="cancelDelete">Cancel</button>

                                        <form method="POST">
                                            <button type="submit" name="delete" class="delete-btn">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- UNSAVED CHANGES MODAL -->
                            <div id="unsavedModal" class="modal">
                                <div class="modal-content">
                                    <h3>Unsaved Changes</h3>
                                    <p>You have unsaved changes. What do you want to do?</p>

                                    <div class="modal-actions">
                                        <button id="discardChanges">Discard</button>
                                        <button id="saveChanges" class="btn-primary">Save</button>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <div class="right">
                        <div class="card preview-card">
                            <h4 class="preview-label">Preview</h4>

                            <div class="preview-box">

                                <img id="preview-img" src="../uploads/default.png" alt="Preview">

                                <h2 id="preview-title">Product Name</h2>

                                <p id="preview-price">₱0.00</p>
                                <p id="preview-description">Add a product description to preview how customers will read it.</p>

                                <span class="product-preview-stock">
                                    <i class="fa-solid fa-circle"></i>
                                    In Stock
                                </span>

                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

    <script>
        document.addEventListener("DOMContentLoaded", function () {

            function isNonNegativeNumberInput(input) {
                return input.matches('input[type="number"][min="0"]');
            }

            document.addEventListener('keydown', function (e) {
                if (isNonNegativeNumberInput(e.target) && e.key === '-') {
                    e.preventDefault();
                }
            }, true);

            document.addEventListener('input', function (e) {
                if (!isNonNegativeNumberInput(e.target) || !e.target.value.includes('-')) {
                    return;
                }

                e.target.value = e.target.value.replace(/-/g, '');
                e.target.dispatchEvent(new Event('input', { bubbles: true }));
            }, true);

            const previewImg = document.getElementById('preview-img');
            const titleInput = document.querySelector('[name="title"]');
            const basePriceInput = document.querySelector('[name="price"]');

            // ✅ IMAGE (INITIAL)
            const firstImage = document.querySelector('#preview .image-item img');

            if (firstImage) {
                previewImg.src = firstImage.src;
            } else {
                previewImg.src = '../uploads/default.png';
            }


            const firstItem = document.querySelector('.image-item');

            if (firstItem) {
                firstItem.classList.add('active-main');
                document.getElementById('mainImage').value = 0;
            }


            // ✅ CLICK EXISTING IMAGES
            document.querySelectorAll('#preview .image-item').forEach((item, index) => {

                item.addEventListener('click', function () {

                    // remove old active
                    document.querySelectorAll('.image-item').forEach(el => {
                        el.classList.remove('active-main');
                    });

                    // set new active
                    item.classList.add('active-main');

                    // update preview
                    const img = item.querySelector('img');
                    previewImg.src = img.src;

                    // ✅ SET MAIN INDEX
                    const items = Array.from(document.querySelectorAll('.image-item'));
                    const newIndex = items.indexOf(item);

                    document.getElementById('mainImage').value = newIndex;
                });

            });

            // ✅ TITLE (INITIAL + LIVE)
            if (titleInput) {
                document.getElementById('preview-title').innerText =
                    titleInput.value || 'Product Name';

                titleInput.addEventListener('input', function () {
                    document.getElementById('preview-title').innerText =
                        this.value || 'Product Name';
                });
            }

            // ✅ PRICE FUNCTION
            const descriptionInput = document.querySelector('[name="description"]');
            const previewDescription = document.getElementById('preview-description');
            if (descriptionInput && previewDescription) {
                previewDescription.innerText =
                    descriptionInput.value || 'Add a product description to preview how customers will read it.';

                descriptionInput.addEventListener('input', function () {
                    previewDescription.innerText =
                        this.value || 'Add a product description to preview how customers will read it.';
                });
            }

            function updatePreviewPrice() {

                const variantPrices = document.querySelectorAll('[name="price[]"]');
                let prices = [];

                variantPrices.forEach(input => {
                    let val = parseFloat(input.value);
                    if (!isNaN(val)) prices.push(val);
                });

                if (prices.length > 0) {
                    const minPrice = Math.min(...prices);
                    document.getElementById('preview-price').innerText =
                        '₱' + minPrice.toFixed(2);
                } else {
                    const value = basePriceInput ? parseFloat(basePriceInput.value) : 0;
                    document.getElementById('preview-price').innerText =
                        '₱' + (value || 0).toFixed(2);
                }
            }

            // ✅ INITIAL PRICE
            updatePreviewPrice();

            // ✅ LIVE PRICE UPDATE
            if (basePriceInput) {
                basePriceInput.addEventListener('input', updatePreviewPrice);
            }

            document.addEventListener('input', function (e) {
                if (e.target.name === 'price[]') {
                    updatePreviewPrice();
                }
            });

            // ✅ IMAGE INPUT (UPLOAD)
            const imageInput = document.getElementById('imageInput');
            if (imageInput) {
                imageInput.addEventListener('change', function (e) {
                    const file = e.target.files[0];
                    if (!file) return;

                    const reader = new FileReader();
                    reader.onload = function (event) {
                        previewImg.src = event.target.result;
                    };
                    reader.readAsDataURL(file);
                });
            }

            let variantStep = 0;

            const addBtn = document.getElementById('add-variant-btn');
            const form = document.getElementById('variant-form');
            const option2 = document.getElementById('option2-wrapper');
            const option3 = document.getElementById('option3-wrapper');
            const generateBtn = document.getElementById('generate-btn');

            const optionName = document.getElementById('option-name');
            const optionValues = document.getElementById('option-values');
            const variantList = document.getElementById('variant-list');
            const basePrice = document.getElementById('base-price-section');

            // =========================
            // ADD VARIANT BUTTON LOGIC
            // =========================
            if (addBtn) {

                addBtn.addEventListener("click", function () {

                    variantStep++;

                    if (variantStep === 1) {
                        form.style.display = 'block';
                        generateBtn.style.display = 'inline-block';
                    }

                    else if (variantStep === 2) {
                        option2.style.display = 'block';
                    }

                    else if (variantStep === 3) {
                        option3.style.display = 'block';
                    }

                    else {
                        alert("Maximum 3 options only");
                    }
                });
            }

            // =========================
            // VALIDATION
            // =========================
            function validate() {
                generateBtn.disabled =
                    optionName.value.trim() === "" ||
                    optionValues.value.trim() === "";
            }

            optionName.addEventListener("input", validate);
            optionValues.addEventListener("input", validate);

            generateBtn.disabled = true;

            // =========================
            // GENERATE VARIANTS
            // =========================
            if (generateBtn) {
                generateBtn.addEventListener("click", function () {

                    const option1Values = document.getElementById('option-values').value
                        .split(',').map(v => v.trim()).filter(v => v);

                    const option2Values = document.getElementById('option2-values').value
                        .split(',').map(v => v.trim()).filter(v => v);

                    const option3Values = document.getElementById('option3-values').value
                        .split(',').map(v => v.trim()).filter(v => v);

                    let html = '';

                    // OPTION 1 ONLY
                    // OPTION 1 ONLY
                    if (option1Values.length && !option2Values.length && !option3Values.length) {

                        option1Values.forEach(val1 => {

                            html += `
                            <div class="variant-row">

                                <div class="col option">${val1}</div>

                                <input type="hidden" name="option1_value[]" value="${val1}">

                                <div class="col">
                                    <input type="text" name="sku[]" placeholder="SKU">
                                </div>

                                <div class="col">
                                    <input type="number" name="price[]" min="0" step="0.01" placeholder="Price">
                                </div>

                                <div class="col">
                                    <input type="number" name="inventory[]" min="0" step="1" placeholder="Stock">
                                </div>

                            </div>`;
                        });
                    }

                    // OPTION 1 + OPTION 2
                    else if (option1Values.length && option2Values.length && !option3Values.length) {

                        option1Values.forEach(val1 => {
                            option2Values.forEach(val2 => {

                                const combined = `${val1} / ${val2}`;

                                html += `
                                <div class="variant-row">

                                    <div class="col option">${combined}</div>

                                    <input type="hidden" name="option1_value[]" value="${val1}">
                                    <input type="hidden" name="option2_value[]" value="${val2}">

                                    <div class="col">
                                        <input type="text" name="sku[]" placeholder="SKU">
                                    </div>

                                    <div class="col">
                                        <input type="number" name="price[]" min="0" step="0.01" placeholder="Price">
                                    </div>

                                    <div class="col">
                                        <input type="number" name="inventory[]" min="0" step="1" placeholder="Stock">
                                    </div>

                                </div>`;
                            });
                        });
                    }

                    // OPTION 1 + OPTION 2 + OPTION 3
                    else if (option1Values.length && option2Values.length && option3Values.length) {

                        option1Values.forEach(val1 => {
                            option2Values.forEach(val2 => {
                                option3Values.forEach(val3 => {

                                    const combined = `${val1} / ${val2} / ${val3}`;

                                    html += `
                                    <div class="variant-row">

                                        <div class="col option">${combined}</div>

                                        <input type="hidden" name="option1_value[]" value="${val1}">
                                        <input type="hidden" name="option2_value[]" value="${val2}">
                                        <input type="hidden" name="option3_value[]" value="${val3}">

                                        <div class="col">
                                            <input type="text" name="sku[]" placeholder="SKU">
                                        </div>

                                        <div class="col">
                                            <input type="number" name="price[]" min="0" step="0.01" placeholder="Price">
                                        </div>

                                        <div class="col">
                                            <input type="number" name="inventory[]" min="0" step="1" placeholder="Stock">
                                        </div>

                                    </div>`;
                                });
                            });
                        });
                    }

                    else {
                        alert("Please enter at least Option 1 values");
                        return;
                    }

                    variantList.innerHTML = html;
                    document.querySelector('.variant-header').style.visibility = 'visible';
                    document.getElementById('reset-variants').style.display = 'inline-block';
                    // hide base price
                    basePrice.style.display = 'none';

                    // ✅ disable base SKU (ADD THIS LINE HERE)
                    const baseSku = document.querySelector('[name="base_sku"]');
                    baseSku.value = '';
                    baseSku.disabled = true;
                    updatePreviewPrice();
                });
            }

            const resetBtn = document.getElementById('reset-variants');


            if (resetBtn) {

                resetBtn.addEventListener('click', function () {
                    variantList.innerHTML = '';
                    document.querySelector('.variant-header').style.visibility = 'hidden';
                    resetBtn.style.display = 'none';
                    basePrice.style.display = '';

                    const baseSku = document.querySelector('[name="base_sku"]');
                    baseSku.disabled = false;
                    baseSku.placeholder = "Enter SKU again";

                    // reset UI
                    variantStep = 0;
                    form.style.display = 'none';
                    option2.style.display = 'none';
                    option3.style.display = 'none';
                    generateBtn.style.display = 'none';
                });
            }


        });

    </script>

    <script>
        let imageFiles = [];
        document.addEventListener("DOMContentLoaded", function () {

            const imageInput = document.getElementById('imageInput');
            const preview = document.getElementById('preview');

            imageInput.addEventListener('change', function (e) {

                const newFiles = Array.from(e.target.files);

                newFiles.forEach(file => {

                    const wrapper = document.createElement('div');
                    wrapper.className = 'image-item';
                    wrapper.dataset.new = "1"; // mark as new image

                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(file);

                    // ✅ DELETE BUTTON
                    const btn = document.createElement('button');
                    btn.type = "button";
                    btn.className = "delete-img-btn";
                    btn.innerHTML = "✕";

                    btn.addEventListener('click', function (ev) {
                        ev.stopPropagation();
                        wrapper.remove();
                    });

                    wrapper.appendChild(img);
                    wrapper.appendChild(btn);

                    document.getElementById('preview').appendChild(wrapper);
                });

                // update preview
                if (newFiles.length > 0) {
                    document.getElementById('preview-img').src =
                        URL.createObjectURL(newFiles[0]);
                }

                // mark as dirty
                isDirty = true;
            });

            function renderImages() {

                const preview = document.getElementById('preview');

                imageFiles.forEach((file, index) => {

                    const wrapper = document.createElement('div');
                    wrapper.className = 'image-item';
                    wrapper.dataset.new = "1"; // mark as new image

                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(file);

                    wrapper.appendChild(img);
                    preview.appendChild(wrapper); // ✅ append, NOT replace
                });

                // update preview image
                if (imageFiles.length > 0) {
                    document.getElementById('preview-img').src =
                        URL.createObjectURL(imageFiles[0]);
                }
            }

            // ✅ SORTABLE INSIDE DOM READY
            new Sortable(preview, {
                animation: 150,
                ghostClass: 'dragging',

                onEnd: function () {

                    const items = document.querySelectorAll('.image-item');
                    let order = [];

                    items.forEach(item => {
                        if (item.dataset.id) { // only DB images
                            order.push(item.dataset.id);
                        }
                    });

                    document.getElementById('imageOrder').value = order.join(',');

                    // update main image
                    const active = document.querySelector('.image-item.active-main');
                    if (active) {
                        const itemsArray = Array.from(items);
                        const newMainIndex = itemsArray.indexOf(active);
                        document.getElementById('mainImage').value = newMainIndex;
                    }
                }
            });

        });
    </script>

    <?php
    $variants = mysqli_query($conn, "
        SELECT * FROM product_variants WHERE product_id='$id'
    ");
    ?>

    <script>
        document.addEventListener("DOMContentLoaded", function () {

            let deletedImages = [];

            function updateImageOrder() {
                const items = document.querySelectorAll('.image-item');
                let order = [];

                items.forEach(item => {
                    order.push(item.dataset.id);
                });

                document.getElementById('imageOrder').value = order.join(',');
            }

            document.querySelectorAll('.delete-img-btn').forEach(btn => {
                btn.addEventListener('click', function () {

                    const item = this.closest('.image-item');
                    const id = item.dataset.id;

                    // store ID
                    deletedImages.push(id);

                    // update hidden input
                    document.getElementById('deleteImages').value = deletedImages.join(',');

                    if (document.querySelectorAll('.image-item').length <= 1) {
                        alert('At least one image is required');
                        return;
                    }

                    // remove from UI
                    item.remove();

                    // ✅ ADD THIS LINE
                    updateImageOrder();

                });
            });

            const variantList = document.getElementById('variant-list');
            const header = document.querySelector('.variant-header');
            const basePrice = document.getElementById('base-price-section');

            let html = '';

            const isDefaultOnly = <?= $isDefaultOnly ? 'true' : 'false' ?>;

            <?php while ($v = mysqli_fetch_assoc($variants)): ?>

                if (!isDefaultOnly) {
                    html += `
                    <div class="variant-row">

                        <div class="col option">
                            <?= $v['option1_value'] ?>
                            <?= $v['option2_value'] ? ' / ' . $v['option2_value'] : '' ?>
                            <?= $v['option3_value'] ? ' / ' . $v['option3_value'] : '' ?>
                        </div>

                        <input type="hidden" name="option1_value[]" value="<?= $v['option1_value'] ?>">
                        <input type="hidden" name="option2_value[]" value="<?= $v['option2_value'] ?>">
                        <input type="hidden" name="option3_value[]" value="<?= $v['option3_value'] ?>">
                        <div class="col">
                            <input type="text" name="sku[]" value="<?= $v['sku'] ?>">
                    </div>

                        <div class="col">
                            <input type="number" name="price[]" min="0" step="0.01" value="<?= $v['price'] ?>">
                        </div>

                        <div class="col">
                            <input type="number" name="inventory[]" min="0" step="1" value="<?= $v['inventory'] ?>">
                        </div>

                    </div>
                    `;
                }

            <?php endwhile; ?>

            if (!isDefaultOnly && html !== '') {
                variantList.innerHTML = html;
                header.style.visibility = 'visible';
                basePrice.style.display = 'none';
                document.getElementById('reset-variants').style.display = 'inline-block';
            }

        });
    </script>

    <script>
        const modal = document.getElementById('deleteModal');
        const openBtn = document.getElementById('openDeleteModal');
        const cancelBtn = document.getElementById('cancelDelete');

        openBtn.addEventListener('click', () => {
            modal.style.display = 'block';
        });

        cancelBtn.addEventListener('click', () => {
            modal.style.display = 'none';
        });

        // close if clicked outside
        window.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });
    </script>

    <script>
        document.querySelectorAll(".category-select").forEach(select => {
            const field = select.closest("form").querySelector(".new-category-field");
            const input = field?.querySelector("input");

            function toggleNewCategoryField() {
                const isNewCategory = select.value === "__new__";

                field.style.display = isNewCategory ? "block" : "none";

                if (input) {
                    input.required = isNewCategory;

                    if (!isNewCategory) {
                        input.value = "";
                    }
                }
            }

            select.addEventListener("change", toggleNewCategoryField);
            toggleNewCategoryField();
        });
    </script>

    <script>
        let isDirty = false;

        // detect input changes
        document.querySelectorAll('input, textarea, select').forEach(el => {
            el.addEventListener('change', () => isDirty = true);
            el.addEventListener('input', () => isDirty = true);
        });

        // detect delete image click
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('delete-img-btn')) {
                isDirty = true;
            }
        });
    </script>

    <script>
        let pendingNavigation = null;

        // intercept links
        document.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', function (e) {
                if (isDirty) {
                    e.preventDefault();
                    pendingNavigation = this.href;
                    document.getElementById('unsavedModal').style.display = 'block';
                }
            });
        });

        // intercept refresh / close
        window.addEventListener('beforeunload', function (e) {
            if (isDirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>

    <script>
        // SAVE
        document.getElementById('saveChanges').onclick = function () {
            document.querySelector('form').submit();
        };

        // DISCARD
        document.getElementById('discardChanges').onclick = function () {
            isDirty = false;
            document.getElementById('unsavedModal').style.display = 'none';

            if (pendingNavigation) {
                window.location.href = pendingNavigation;
            } else {
                location.reload();
            }
        };

        // reset dirty after submit
        document.querySelector('form').addEventListener('submit', () => {
            isDirty = false;
        });
    </script>

</body>

</html>
