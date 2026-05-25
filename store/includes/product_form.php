<div class="product-editor-card">
    <form id="productForm" class="product-editor-form product-editor-form--legacy" method="POST" enctype="multipart/form-data">

        <label>Title</label>
        <input type="text" name="title" required>

        <label>Description</label>
        <textarea name="description"></textarea>

        <label>Product Image</label>
        <input type="file" name="images[]" id="imageInput" accept="image/*" multiple>
        <input type="hidden" name="image_order" id="imageOrder">
        <input type="hidden" name="main_image" id="mainImage">
        <div id="preview"></div>

        <label>Category</label>
        <select name="category_id" class="category-select" required>
            <option value="">Select Category</option>

            <?php
            $cat = mysqli_query($conn, "SELECT * FROM categories");
            while ($c = mysqli_fetch_assoc($cat)) {
                echo "<option value='{$c['id']}'>" . htmlspecialchars($c['name']) . "</option>";
            }
            ?>
            <option value="__new__">+ Add new category</option>
        </select>

        <div class="new-category-field" style="display:none;">
            <label>New Category</label>
            <input type="text" name="new_category_name" placeholder="Category name">
        </div>

        <label>Status</label>
        <select name="status" required>
            <option value="active">Active</option>
            <option value="draft">Draft</option>
        </select>

        <div class="three-cols" id="base-price-section">
            <div class="field">
                <label>Price</label>
                <input type="number" name="price" min="0" step="0.01" placeholder="Base Price">
            </div>

            <div class="field">
                <label>SKU</label>
                <input type="text" name="base_sku" placeholder="Product SKU">
            </div>

            <div class="field">
                <label>Stock</label>
                <input type="number" name="inventory" min="0" step="1" placeholder="Available Stock">
            </div>
        </div>

        <h3>Variants</h3>

        <div id="variant-form" style="display:none; margin-top:15px;">
            <label>Option Name</label>
            <input type="text" id="option-name" name="option_name" placeholder="e.g. Size or Color">

            <label>Option Values</label>
            <input type="text" id="option-values" placeholder="e.g. Small, Medium, Large">

            <div id="option2-wrapper" style="display:none;">
                <label>Option 2 Name</label>
                <input type="text" id="option2-name" name="option2_name" placeholder="e.g. Size">

                <label>Option 2 Values</label>
                <input type="text" id="option2-values" placeholder="e.g. Small, Large">
            </div>

            <div id="option3-wrapper" style="display:none;">
                <label>Option 3 Name</label>
                <input type="text" id="option3-name" name="option3_name" placeholder="e.g. Add-ons">

                <label>Option 3 Values</label>
                <input type="text" id="option3-values" placeholder="e.g. Cheese, Drink">
            </div>
        </div>

        <div class="product-editor-inline-actions">
            <button type="button" id="add-variant-btn">+ Add Variant</button>
            <button type="button" id="reset-variants" style="display:none;">Remove Variants</button>
            <button type="button" id="generate-btn" style="display:none;">Generate Variants</button>
        </div>

        <div class="variant-header" style="visibility: hidden;">
            <span>Option</span>
            <span>SKU</span>
            <span>Price</span>
            <span>Stock</span>
        </div>

        <div id="variant-list"></div>

        <div class="product-form-actions">
            <button type="submit" name="save">
                <i class="fa-regular fa-floppy-disk"></i>
                Save Product
            </button>
        </div>
    </form>
</div>

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
