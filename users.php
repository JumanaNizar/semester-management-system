<?php
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';

$me = current_user();
if (!$me || $me['role'] !== 'ADMIN') {
    flash_set('error', 'Only administrators can manage users.');
    redirect('dashboard.php');
}
$pdo = db();

$roles  = ['ADMIN', 'LECTURER', 'VIEWER'];
$errors = [];
$form   = ['username' => '', 'role' => 'VIEWER', 'lecturer_id' => ''];

// ---------- Handle form submissions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uid    = trim($_POST['user_id'] ?? '');

    if ($action === 'create') {
        $form['username']    = trim($_POST['username'] ?? '');
        $form['role']        = trim($_POST['role'] ?? '');
        $form['lecturer_id'] = trim($_POST['lecturer_id'] ?? '');
        $password            = (string)($_POST['password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $form['username'])) {
            $errors[] = 'Username must be 3 to 50 characters: letters, digits, dot, dash or underscore.';
        }
        if (strlen($password) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }
        if (!in_array($form['role'], $roles, true)) {
            $errors[] = 'Please choose a role.';
        }
        $lecId = null;
        if ($form['role'] === 'LECTURER') {
            $q = $pdo->prepare('SELECT 1 FROM lecturer WHERE lecturer_id = ?');
            $q->execute([$form['lecturer_id']]);
            if (!$q->fetchColumn()) {
                $errors[] = 'A Lecturer account must be linked to an existing lecturer.';
            } else {
                $lecId = $form['lecturer_id'];
            }
        }

        if (!$errors) {
            try {
                $pdo->prepare('INSERT INTO users (username, password_hash, role, lecturer_id) VALUES (?, ?, ?, ?)')
                    ->execute([$form['username'], password_hash($password, PASSWORD_DEFAULT), $form['role'], $lecId]);
                flash_set('success', 'User created successfully.');
                redirect('users.php');
            } catch (PDOException $ex) {
                if (($ex->errorInfo[1] ?? 0) === 1062) {
                    $errors[] = 'That username is already taken.';
                } else {
                    error_log('User create failed: ' . $ex->getMessage());
                    $errors[] = 'Could not create the user. Please try again.';
                }
            }
        }
    } elseif (in_array($action, ['reset', 'toggle', 'delete'], true)) {
        if (!ctype_digit($uid)) {
            flash_set('error', 'Invalid user.');
            redirect('users.php');
        }
        $uid = (int)$uid;

        if ($action === 'reset') {
            $pw = (string)($_POST['new_password'] ?? '');
            if (strlen($pw) < 10) {
                flash_set('error', 'New password must be at least 10 characters.');
            } else {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                    ->execute([password_hash($pw, PASSWORD_DEFAULT), $uid]);
                flash_set('success', 'Password reset.');
            }
        } elseif ($uid === $me['id']) {
            flash_set('error', 'You cannot disable or delete your own account.');
        } elseif ($action === 'toggle') {
            $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE user_id = ?')->execute([$uid]);
            flash_set('success', 'Account status changed.');
        } else {
            $stmt = $pdo->prepare('DELETE FROM users WHERE user_id = ?');
            $stmt->execute([$uid]);
            flash_set('success', $stmt->rowCount() ? 'User deleted.' : 'User not found.');
        }
        redirect('users.php');
    }
}

// ---------- Data for the page ----------
$users = $pdo->query(
    'SELECT u.user_id, u.username, u.role, u.is_active, u.created_at, u.lecturer_id, l.lecturer_name
     FROM users u LEFT JOIN lecturer l ON l.lecturer_id = u.lecturer_id
     ORDER BY u.username')->fetchAll();
$lecturers = $pdo->query('SELECT lecturer_id, lecturer_name FROM lecturer ORDER BY lecturer_name')->fetchAll();

$pageTitle = 'Users';
$active = 'users';
include __DIR__ . '/includes/header.php';
?>
<main>
  <div class="container">
    <h1 class="page-title">Users</h1>
    <p class="page-sub">Create accounts and control who can log in. Administrators only.</p>

    <?= flash_render() ?>

    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Username</th><th>Role</th><th>Linked lecturer</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php foreach ($users as $u): $self = ((int)$u['user_id'] === $me['id']); ?>
            <tr>
              <td><?= e($u['username']) ?><?= $self ? ' (you)' : '' ?></td>
              <td><span class="badge badge-blue"><?= e($u['role']) ?></span></td>
              <td><?= e($u['lecturer_name'] ?? '') ?></td>
              <td><?= (int)$u['is_active'] === 1 ? 'Active' : 'Disabled' ?></td>
              <td>
                <div class="actions" style="flex-wrap:wrap">
                  <form method="post" action="users.php" class="actions">
                    <input type="hidden" name="action" value="reset">
                    <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                    <input type="password" name="new_password" class="pw-input" minlength="10"
                           placeholder="New password" required>
                    <button type="submit" class="btn btn-sm btn-light">Reset</button>
                  </form>
                  <?php if (!$self): ?>
                    <form method="post" action="users.php">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                      <button type="submit" class="btn btn-sm btn-light">
                        <?= (int)$u['is_active'] === 1 ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <form method="post" action="users.php">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger"
                              data-confirm="Delete user <?= e($u['username']) ?>?">Delete</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card section-gap">
      <h3 style="color:var(--navy);margin-bottom:14px">Add a user</h3>
      <?= errors_render($errors) ?>
      <form method="post" action="users.php" autocomplete="off">
        <input type="hidden" name="action" value="create">
        <div class="form-row">
          <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" maxlength="50" required value="<?= e($form['username']) ?>">
          </div>
          <div class="form-group">
            <label for="password">Password (at least 10 characters)</label>
            <input type="password" id="password" name="password" minlength="10" required>
          </div>
          <div class="form-group">
            <label for="role">Role</label>
            <select id="role" name="role" required>
              <?php foreach ($roles as $r): ?>
                <option value="<?= e($r) ?>" <?= $form['role'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="lecturer_id">Linked lecturer (for LECTURER role)</label>
            <select id="lecturer_id" name="lecturer_id">
              <option value="">None</option>
              <?php foreach ($lecturers as $l): ?>
                <option value="<?= e($l['lecturer_id']) ?>" <?= $form['lecturer_id'] === $l['lecturer_id'] ? 'selected' : '' ?>>
                  <?= e($l['lecturer_id']) ?> &ndash; <?= e($l['lecturer_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <button type="submit" class="btn btn-gold">Create User</button>
      </form>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>