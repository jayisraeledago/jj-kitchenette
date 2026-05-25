<?php
session_start();
include '../db.php';

// CHECK LOGIN
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];

// GET USER
$stmt = $conn->prepare("
    SELECT *
    FROM users
    WHERE id = ?
");

$stmt->bind_param("i", $userId);
$stmt->execute();

$user = $stmt->get_result()->fetch_assoc();

$fullName = $user['first_name'] . ' ' . $user['last_name'];

// GET FIRST LETTER
$initial = strtoupper(substr($user['first_name'], 0, 1));

// GET ADDRESSES
$addressStmt = $conn->prepare("
    SELECT *
    FROM addresses
    WHERE user_id = ?
    ORDER BY is_default DESC, id DESC
");

$addressStmt->bind_param("i", $userId);
$addressStmt->execute();

$addressResult = $addressStmt->get_result();
$addresses = [];
while ($address = $addressResult->fetch_assoc()) {
    $addresses[] = $address;
}
$defaultAddress = $addresses[0] ?? null;
$profilePhone = $defaultAddress['phone'] ?? 'No phone saved';
$memberSince = !empty($user['created_at']) ? date('M d, Y', strtotime($user['created_at'])) : 'Member';

$pageTitle = "My Profile | J&J's Kitchenette";
$pageCSS = "profile.css";

include('../store/includes/header.php');
?>

    <!-- CONTENT -->
    <div class="account-page">

        <div class="profile-page-top">
            <div>
                <h1 id="profile">My Profile <i class="fas fa-leaf"></i></h1>
                <p>Manage your account information, addresses, and preferences.</p>
            </div>

            <a href="/account/orders.php" class="profile-orders-link">
                <i class="fas fa-bag-shopping"></i>
                Order History
                <i class="fas fa-arrow-right"></i>
            </a>
        </div>

        <?php if (isset($_GET['updated'])): ?>

            <div class="success-message">
                Profile updated successfully.
            </div>

        <?php endif; ?>

        <!-- PROFILE CARD -->
        <div class="profile-card profile-summary-card">

            <div class="profile-top">
                <div class="profile-identity">
                    <div class="profile-avatar-large">
                        <i class="fas fa-user"></i>
                    </div>

                    <div>
                        <h3><?= htmlspecialchars($fullName) ?></h3>

                        <p>
                            <i class="far fa-envelope"></i>
                            <?= htmlspecialchars($user['email']) ?>
                        </p>

                        <p>
                            <i class="fas fa-phone"></i>
                            <?= htmlspecialchars($profilePhone) ?>
                        </p>
                    </div>
                </div>

                <div class="profile-summary-actions">
                    <button class="edit-btn" onclick="openModal()">
                        <i class="fas fa-pen"></i>
                        Edit Profile
                    </button>

                    <div class="profile-member-info">
                        <span>Member since</span>
                        <strong><i class="far fa-calendar-days"></i> <?= htmlspecialchars($memberSince) ?></strong>
                    </div>
                </div>
            </div>

        </div>

        <!-- ADDRESS CARD -->
        <div class="profile-card address-panel">

            <div class="address-header">
                <div class="address-title">
                    <i class="fas fa-location-dot"></i>
                    <div>
                        <h3>Addresses</h3>
                        <p>Manage your saved addresses for faster checkout.</p>
                    </div>
                </div>

                <button class="add-btn" onclick="openAddressModal()">
                    <i class="fas fa-plus"></i>
                    Add New Address
                </button>

            </div>

            <?php if (!empty($addresses)): ?>

                <div class="address-grid">

                    <?php foreach ($addresses as $address): ?>

                        <div class="address-box<?php echo $address['is_default'] == 1 ? ' is-default' : ''; ?>">

                            <div class="address-top">

                                <?php if ($address['is_default'] == 1): ?>

                                    <p class="default-label">
                                        <i class="fas fa-star"></i>
                                        Default
                                    </p>

                                <?php else: ?>

                                    <div class="default-placeholder"></div>

                                <?php endif; ?>

                                <div class="address-actions">

                                    <button class="edit-address-btn" onclick='openEditAddressModal(
                                            <?= $address["id"] ?>,
                                            <?= json_encode($address["full_name"]) ?>,
                                            <?= json_encode($address["address_line"]) ?>,
                                            <?= json_encode($address["city"]) ?>,
                                            <?= json_encode($address["phone"]) ?>,
                                            <?= $address["is_default"] ?>
                                        )'>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round">

                                            <path d="M12 20h9" />
                                            <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />

                                        </svg>
                                    </button>

                                    <?php if ($address['is_default'] != 1): ?>

                                        <form
                                            action="delete_address.php"
                                            method="POST"
                                            class="delete-address-form"
                                        >
                                            <input type="hidden" name="address_id" value="<?= $address['id'] ?>">

                                            <button type="submit" class="delete-address-btn">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                    stroke-linecap="round" stroke-linejoin="round">

                                                    <path d="M3 6h18" />
                                                    <path d="M8 6V4h8v2" />
                                                    <path d="M19 6l-1 14H6L5 6" />
                                                    <path d="M10 11v6" />
                                                    <path d="M14 11v6" />

                                                </svg>
                                            </button>
                                        </form>

                                    <?php endif; ?>

                                </div>

                            </div>

                            <div class="address-content">

                                <strong><?= htmlspecialchars($address['full_name']) ?></strong>
                                <span><?= htmlspecialchars($address['address_line']) ?></span>
                                <span><?= htmlspecialchars($address['city']) ?></span>
                                <span><?= htmlspecialchars($address['phone']) ?></span>

                            </div>

                            <?php if ($address['is_default'] == 1): ?>
                                <span class="address-check">
                                    <i class="fas fa-check"></i>
                                </span>
                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="address-box">

                    <div class="address-content">
                        No address yet.
                    </div>

                </div>

            <?php endif; ?>

        </div>

        <div class="security-tip">
            <i class="fas fa-shield-halved"></i>
            <div>
                <h3>Security Tip</h3>
                <p>Keep your account secure. Never share your password with anyone.</p>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="profile-footer">

            <a href="logout.php" class="logout-btn">
                <i class="fas fa-right-from-bracket"></i>
                Sign out
            </a>

        </div>

        <!-- EDIT MODAL -->
        <div class="modal" id="editModal">

            <div class="modal-content">

                <div class="modal-header">

                    <h2>Edit Profile</h2>

                    <span class="close-modal" onclick="closeModal()">
                        &times;
                    </span>

                </div>

                <form action="update_profile.php" method="POST">

                    <div class="form-group">

                        <label>First Name</label>

                        <input type="text" name="first_name" value="<?= htmlspecialchars($user['first_name']) ?>"
                            required>

                    </div>

                    <div class="form-group">

                        <label>Last Name</label>

                        <input type="text" name="last_name" value="<?= htmlspecialchars($user['last_name']) ?>"
                            required>

                    </div>

                    <div class="form-group">

                        <label>Email</label>

                        <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>

                    </div>

                    <button type="submit" class="save-btn">
                        Save Changes
                    </button>

                </form>

            </div>

        </div>

    </div>

    <script>

        function openModal() {
            document.getElementById('editModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // CLOSE WHEN CLICK OUTSIDE
        window.onclick = function (event) {

            const modal = document.getElementById('editModal');

            if (event.target == modal) {
                modal.style.display = "none";
            }
        }

    </script>

    <script>

        setTimeout(() => {

            const success = document.querySelector('.success-message');

            if (success) {
                success.style.display = 'none';
            }

        }, 3000);

    </script>

    <!-- ADD ADDRESS MODAL -->
    <div class="modal" id="addressModal">

        <div class="modal-content">

            <div class="modal-header">

                <h2>Add Address</h2>

                <span class="close-modal" onclick="closeAddressModal()">
                    &times;
                </span>

            </div>

            <form action="save_address.php" method="POST">

                <div class="form-group">
                    <label>Recipient Name</label>

                    <input type="text" name="full_name" required>
                </div>

                <div class="form-group">
                    <label>Address</label>

                    <input type="text" name="address_line" required>
                </div>

                <div class="form-group">
                    <label>City</label>

                    <input type="text" name="city" required>
                </div>

                <div class="form-group">
                    <label>Phone</label>

                    <input type="text" name="phone" required>
                </div>

                <label class="default-check">

                    <input type="checkbox" name="is_default">

                    This is my default address

                </label>

                <button type="submit" class="save-btn">
                    Save Address
                </button>

            </form>

        </div>

    </div>

        <!-- EDIT ADDRESS MODAL -->
        <div class="modal" id="editAddressModal">

        <div class="modal-content">

            <div class="modal-header">

                <h2>Edit Address</h2>

                <span class="close-modal" onclick="closeEditAddressModal()">
                    &times;
                </span>

            </div>

            <form action="update_address.php" method="POST">

                <input type="hidden" name="address_id" id="edit_address_id">

                <div class="form-group">
                    <label>Recipient Name</label>

                    <input type="text" name="full_name" id="edit_full_name" required>
                </div>

                <div class="form-group">
                    <label>Address</label>

                    <input type="text" name="address_line" id="edit_address_line" required>
                </div>

                <div class="form-group">
                    <label>City</label>

                    <input type="text" name="city" id="edit_city" required>
                </div>

                <div class="form-group">
                    <label>Phone</label>

                    <input type="text" name="phone" id="edit_phone" required>
                </div>

                <label class="default-check">

                    <input type="checkbox" name="is_default" id="edit_is_default">

                    This is my default address

                </label>

                <button type="submit" class="save-btn">
                    Update Address
                </button>

            </form>

        </div>

        </div>

        <!-- DELETE ADDRESS MODAL -->
        <div class="modal" id="deleteAddressModal">

            <div class="modal-content confirm-modal-content">

                <div class="confirm-modal-icon">
                    <i class="fas fa-trash-can"></i>
                </div>

                <h2>Delete address?</h2>

                <p>This address will be removed from your saved addresses.</p>

                <div class="confirm-modal-actions">
                    <button type="button" class="confirm-cancel-btn" onclick="closeDeleteAddressModal()">
                        Cancel
                    </button>

                    <button type="button" class="confirm-delete-btn" onclick="confirmDeleteAddress()">
                        Delete
                    </button>
                </div>

            </div>

        </div>

    <script>

        // ADD MODAL
        function openAddressModal() {
            document.getElementById('addressModal').style.display = 'flex';
        }

        function closeAddressModal() {
            document.getElementById('addressModal').style.display = 'none';
        }

        // EDIT MODAL
        function openEditAddressModal(
            id,
            fullName,
            address,
            city,
            phone,
            isDefault
        ) {

            document.getElementById('edit_address_id').value = id;
            document.getElementById('edit_full_name').value = fullName;
            document.getElementById('edit_address_line').value = address;
            document.getElementById('edit_city').value = city;
            document.getElementById('edit_phone').value = phone;

            document.getElementById('edit_is_default').checked = isDefault == 1;

            document.getElementById('editAddressModal').style.display = 'flex';
        }

        function closeEditAddressModal() {
            document.getElementById('editAddressModal').style.display = 'none';
        }

        let pendingDeleteAddressForm = null;

        document.querySelectorAll('.delete-address-form').forEach(form => {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                pendingDeleteAddressForm = form;
                document.getElementById('deleteAddressModal').style.display = 'flex';
            });
        });

        function closeDeleteAddressModal() {
            document.getElementById('deleteAddressModal').style.display = 'none';
            pendingDeleteAddressForm = null;
        }

        function confirmDeleteAddress() {
            if (pendingDeleteAddressForm) {
                pendingDeleteAddressForm.submit();
            }
        }

    </script>

</body>

</html>
