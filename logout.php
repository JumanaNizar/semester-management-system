<?php
require __DIR__ . '/includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logout_user();
    flash_set('success', 'You have been logged out.');
}
redirect('login.php');