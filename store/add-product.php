<?php
include __DIR__ . '/../db.php';
include __DIR__ . '/includes/functions.php';
include __DIR__ . '/includes/admin-auth.php';
requireAdminPermission($conn, ['products']);

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


    // VALIDATION
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

    $check = mysqli_query($conn, "SELECT id FROM products WHERE handle='$handle'");
    if (mysqli_num_rows($check) > 0) {
        $handle .= '-' . time();
    }

    // INSERT PRODUCT
    $stmt = $conn->prepare("
    INSERT INTO products (handle, title, body, category_id, status)
    VALUES (?, ?, ?, ?, ?)
");

    $stmt->bind_param("sssis", $handle, $title, $description, $category_id, $status);
    $stmt->execute();

    if ($stmt->error) {
        die("Error inserting product: " . $stmt->error);
    }

    $product_id = $conn->insert_id;

    $uploadedImages = uploadProductImages($conn, $product_id);

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

    // SAVE ALL OPTIONS
    saveOption($conn, $product_id, $option1_name, $option1);
    saveOption($conn, $product_id, $option2_name, $option2);
    saveOption($conn, $product_id, $option3_name, $option3);




    // =========================
    // INSERT VARIANTS
    // =========================
    $reservedSkus = [];

    if (!empty($option1)) {

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
            $sku = ensureUniqueVariantSku($conn, $sku, $reservedSkus);

            $result = mysqli_query($conn, "
                INSERT INTO product_variants 
                (product_id, option1_value, option2_value, option3_value, price, inventory, sku)
                VALUES 
                ('$product_id', '$opt1', $opt2_sql, $opt3_sql, '$price', '$stock', '$sku')
            ");

            if (!$result) {
                die("Variant insert error: " . mysqli_error($conn));
            }
        }

    } else {




        // fallback if no variants
        $base_price = floatval($_POST['price'] ?? 0);
        $base_stock = intval($_POST['inventory'] ?? 0); // ✅ ADD THIS
        $base_sku = mysqli_real_escape_string($conn, $_POST['base_sku'] ?? '');

        if (empty($base_sku)) {
            $base_sku = generateSkuFromTitle($title);
        }
        $base_sku = ensureUniqueVariantSku($conn, $base_sku, $reservedSkus);

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
    <title>Add Product | J&J's Kitchenette Admin</title>
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
                            <h1>Add Product</h1>
                            <p>Create a new product, add images, pricing, and inventory.</p>
                        </div>

                        <div class="product-editor-actions">
                            <button type="submit" form="productForm" name="save">
                                <i class="fa-regular fa-floppy-disk"></i>
                                Save Product
                            </button>
                        </div>
                    </div>
                </div>

                <div class="layout product-editor-layout">

                    <!-- LEFT -->
                    <div class="left">
                        <?php include 'includes/product_form.php'; ?>
                    </div>

                    <div class="right">
                        <?php include 'includes/product_preview.php'; ?>
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

            function updatePreviewPrice() {

                const variantPrices = document.querySelectorAll('[name="price[]"]');

                let prices = [];

                // collect variant prices
                variantPrices.forEach(input => {
                    let val = parseFloat(input.value);
                    if (!isNaN(val)) {
                        prices.push(val);
                    }
                });

                // if variants exist → use lowest
                if (prices.length > 0) {
                    const minPrice = Math.min(...prices);
                    document.getElementById('preview-price').innerText = '₱' + minPrice.toFixed(2);
                }

                // else fallback to base price
                else {
                    const base = document.querySelector('[name="price"]');
                    const value = base ? parseFloat(base.value) : 0;

                    document.getElementById('preview-price').innerText =
                        '₱' + (value || 0).toFixed(2);
                }
            }

            const basePriceInput = document.querySelector('[name="price"]');
            if (basePriceInput) {
                basePriceInput.addEventListener('input', updatePreviewPrice);
            }

            document.addEventListener('input', function (e) {
                if (e.target.name === 'price[]') {
                    updatePreviewPrice();
                }
            });

            const titleInput = document.querySelector('[name="title"]');
            if (titleInput) {
                titleInput.addEventListener('input', function () {
                    document.getElementById('preview-title').innerText =
                        this.value || 'Product Name';
                });
            }

            const descriptionInput = document.querySelector('[name="description"]');
            const previewDescription = document.getElementById('preview-description');
            if (descriptionInput && previewDescription) {
                descriptionInput.addEventListener('input', function () {
                    previewDescription.innerText =
                        this.value || 'Add a product description to preview how customers will read it.';
                });
            }

            const imageInput = document.getElementById('imageInput');
            if (imageInput) {
                imageInput.addEventListener('change', function (e) {
                    const file = e.target.files[0];

                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function (event) {
                            document.getElementById('preview-img').src = event.target.result;
                        };
                        reader.readAsDataURL(file);
                    }
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

                    // disable base SKU
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
                    baseSku.placeholder = "Product SKU";

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
                imageFiles = Array.from(e.target.files);

                document.getElementById('imageOrder').value =
                    imageFiles.map((_, i) => i).join(',');

                // default main
                document.getElementById('mainImage').value = 0;

                renderImages();

            });

            function renderImages() {
                preview.innerHTML = '';

                const mainIndex = parseInt(document.getElementById('mainImage').value) || 0;

                imageFiles.forEach((file, index) => {

                    const wrapper = document.createElement('div');
                    wrapper.className = 'image-item';
                    wrapper.dataset.index = index;

                    if (index === mainIndex) {
                        wrapper.classList.add('active-main');

                        // update preview image
                        document.getElementById('preview-img').src = URL.createObjectURL(file);
                    }

                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(file);

                    wrapper.addEventListener('click', function () {

                        document.querySelectorAll('.image-item').forEach(el => {
                            el.classList.remove('active-main');
                        });

                        wrapper.classList.add('active-main');

                        const items = Array.from(document.querySelectorAll('.image-item'));
                        const position = items.indexOf(wrapper);

                        document.getElementById('mainImage').value = position;

                        // update preview on click
                        document.getElementById('preview-img').src = img.src;
                    });

                    wrapper.appendChild(img);
                    preview.appendChild(wrapper);
                });
            }

            new Sortable(preview, {
                animation: 150,
                ghostClass: 'dragging',

                onEnd: function () {

                    const items = document.querySelectorAll('.image-item');
                    const active = document.querySelector('.image-item.active-main');

                    let newOrder = [];
                    let orderIndexes = [];

                    // rebuild order + update dataset
                    items.forEach((item, newIndex) => {
                        const oldIndex = item.dataset.index;

                        newOrder.push(imageFiles[oldIndex]);

                        // update dataset to NEW position
                        item.dataset.index = newIndex;

                        orderIndexes.push(newIndex); // ✅ important fix
                    });

                    // fix main image index AFTER reorder
                    if (active) {
                        const itemsArray = Array.from(document.querySelectorAll('.image-item'));
                        const newMainIndex = itemsArray.indexOf(active);

                        document.getElementById('mainImage').value = newMainIndex;
                    }

                    // update imageFiles + hidden inputs
                    imageFiles = newOrder;
                    document.getElementById('imageOrder').value = orderIndexes.join(',');

                    // RE-RENDER LAST
                    renderImages();
                }
            });

        });
    </script>

</body>

</html>
