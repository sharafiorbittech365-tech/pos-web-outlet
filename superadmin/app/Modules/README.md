# Super Admin modules

One folder per feature: `Controllers/`, `Models/`, `Services/`, `Views/`, `Config/Routes.php`.
Controllers stay thin; business rules go in `Services/` (or in omnipos/core when another app needs them too).
