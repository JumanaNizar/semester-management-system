<?php
require_once __DIR__ . '/helpers.php';

$pageTitle = $pageTitle ?? 'Semester Management System';
$active    = $active ?? '';
$user      = current_user();

function navLink(string $file, string $label, string $active, string $key): string {
    $class = ($active === $key) ? ' class="active"' : '';
    return '<li><a href="' . $file . '"' . $class . '>' . $label . '</a></li>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e($pageTitle) ?> | Semester Management</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="role-<?= e($user['role'] ?? 'GUEST') ?> page-<?= e($active) ?>">
<nav class="navbar">
  <div class="container">
    <a class="brand" href="index.php">Semester<span>MS</span></a>
    <button class="menu-btn" id="menuBtn" aria-label="Menu">&#9776;</button>
    <ul class="nav-links" id="navLinks">
      <?= navLink('index.php', 'Home', $active, 'home') ?>
<?php if ($user): ?>
      <?= navLink('dashboard.php', 'Dashboard', $active, 'dashboard') ?>
      <?= navLink('semesters.php', 'Semesters', $active, 'semesters') ?>
      <?= navLink('courses.php', 'Courses', $active, 'courses') ?>
      <?= navLink('lecturers.php', 'Lecturers', $active, 'lecturers') ?>
      <?= navLink('demonstrators.php', 'Demonstrators', $active, 'demonstrators') ?>
      <?= navLink('venues.php', 'Venues', $active, 'venues') ?>
      <?= navLink('timetable.php', 'Timetable', $active, 'timetable') ?>
      <?php if ($user['role'] === 'ADMIN'): ?>
      <?= navLink('users.php', 'Users', $active, 'users') ?>
<?php endif; ?>
      <li class="nav-user"><?= e($user['username']) ?> (<?= e($user['role']) ?>)</li>
      <li>
        <form method="post" action="logout.php">
          <button type="submit" class="logout-btn">Logout</button>
        </form>
      </li>
<?php else: ?>
      <li><a class="logout" href="login.php">Login</a></li>
<?php endif; ?>
    </ul>
  </div>
</nav>