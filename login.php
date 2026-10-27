<?php
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';

if (!empty($_SESSION['user'])) {
    redirect('dashboard.php');
}

$error = '';
$username = '';
$maxFails = 5;
$lockSeconds = 300;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (($_SESSION['lock_until'] ?? 0) > time()) {
        $error = 'Too many failed attempts. Please wait a few minutes and try again.';
    } else {
        try {
            $stmt = db()->prepare('SELECT user_id, username, password_hash, role, lecturer_id, is_active
                                   FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user) {
                password_hash('dummy-password', PASSWORD_DEFAULT);   // similar timing for unknown users
            }
            $ok = $user && (int)$user['is_active'] === 1 && password_verify($password, $user['password_hash']);

            if ($ok) {
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
                }
                unset($_SESSION['fails'], $_SESSION['lock_until']);
                login_user($user);
                redirect('dashboard.php');
            }

            $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
            if ($_SESSION['fails'] >= $maxFails) {
                $_SESSION['lock_until'] = time() + $lockSeconds;
                $_SESSION['fails'] = 0;
            }
            $error = 'Invalid username or password.';
        } catch (PDOException $ex) {
            error_log('Login failed: ' . $ex->getMessage());
            $error = 'Could not log in right now. Please try again.';
        }
    }
}

$noUsers = false;
try {
    $noUsers = ((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0);
} catch (PDOException $ex) {
    // ignore
}

$pageTitle = 'Login';
$active = '';
include __DIR__ . '/includes/header.php';
?>
<main>
  <div class="container">
    <div class="card login-box">
      <h2>Welcome back</h2>
      <p class="page-sub">Sign in to manage the semester system.</p>

      <?= flash_render() ?>
      <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
      <?php if ($noUsers): ?>
        <div class="note">No accounts exist yet. <a href="setup_admin.php"><b>Create the first admin account</b></a>.</div>
      <?php endif; ?>

      <form method="post" action="login.php" autocomplete="off">
        <div class="form-group">
          <label for="username">Username</label>
          <input type="text" id="username" name="username" maxlength="50" required value="<?= e($username) ?>">
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-gold" style="width:100%">Sign In</button>
      </form>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>