<?php
/** Signs the admin out (destroys the session). */
require __DIR__ . '/../includes/init.php';
admin_logout();
redirect(url('admin/login.php'));
