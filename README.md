# J&J's Kitchenette

PHP and MySQL storefront/admin project for J&J's Kitchenette.

## Local Setup

1. Place the project folder inside `C:\xampp\htdocs`.
2. Start Apache and MySQL from XAMPP.
3. Create a MySQL database named `jj_kitchenette`.
4. Copy `db.example.php` to `db.php`.
5. Update `db.php` if your local MySQL username, password, or database name is different.
6. Open `http://localhost/jj_kitchenette/`.

## Admin

Admin and staff users log in at:

`http://localhost/jj_kitchenette/store/login.php`

Customer users log in at:

`http://localhost/jj_kitchenette/login.php`

## Notes

- Runtime uploads are ignored by Git through `.gitignore`.
- Database exports are not included by default.
