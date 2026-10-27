<?php
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
$pdo = db();

$errors = [];
$form = ['original_id' => '', 'lecturer_id' => '', 'lecturer_name' => '', 'email' => '',
         'phones' => '', 'lecturer_type' => 'Regular'];

// Turn "077 111, 071 222" into a clean list of unique phone numbers.
function parse_phones(string $text): array
{
    $parts = preg_split('/[,;\n]+/', $text);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $out[$p] = $p;
        }
    }
    return array_values($out);
}

// ---------- Handle form submissions (add / update / delete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = trim($_POST['lecturer_id'] ?? '');
        try {
            $stmt = $pdo->prepare('DELETE FROM lecturer WHERE lecturer_id = ?');
            $stmt->execute([$id]);
            flash_set('success', $stmt->rowCount() ? 'Lecturer deleted.' : 'Lecturer not found.');
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                flash_set('error', 'Cannot delete this lecturer because they are used in the timetable. Remove those timetable entries first.');
            } else {
                error_log('Lecturer delete failed: ' . $ex->getMessage());
                flash_set('error', 'Could not delete the lecturer. Please try again.');
            }
        }
        redirect('lecturers.php');
    }

    if ($action === 'save') {
        foreach (array_keys($form) as $k) {
            $form[$k] = trim($_POST[$k] ?? '');
        }
        $editing = ($form['original_id'] !== '');
        if ($editing) {
            $form['lecturer_id'] = $form['original_id'];   // ID cannot be changed
        }
        $phones = parse_phones($form['phones']);

        if (!preg_match('/^[A-Za-z0-9]{4}$/', $form['lecturer_id'])) {
            $errors[] = 'Lecturer ID must be exactly 4 letters or digits.';
        }
        if ($form['lecturer_name'] === '' || mb_strlen($form['lecturer_name']) > 100) {
            $errors[] = 'Name is required and must be 100 characters or fewer.';
        }
        if (mb_strlen($form['email']) > 120 || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address (120 characters or fewer).';
        }
        if (count($phones) > 5) {
            $errors[] = 'You can enter at most 5 phone numbers.';
        }
        foreach ($phones as $p) {
            if (!preg_match('/^[0-9+\- ]{5,15}$/', $p)) {
                $errors[] = 'Phone number "' . $p . '" is not valid (5 to 15 digits, + or - allowed).';
                break;
            }
        }
        if (!in_array($form['lecturer_type'], ['Regular', 'In-charge', 'Visiting'], true)) {
            $errors[] = 'Please choose a lecturer type.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $id = $form['lecturer_id'];

                if (!$editing) {
                    $pdo->prepare('INSERT INTO lecturer (lecturer_id, lecturer_name, email) VALUES (?, ?, ?)')
                        ->execute([$id, $form['lecturer_name'], $form['email']]);
                } else {
                    $pdo->prepare('UPDATE lecturer SET lecturer_name = ?, email = ? WHERE lecturer_id = ?')
                        ->execute([$form['lecturer_name'], $form['email'], $id]);
                    $pdo->prepare('DELETE FROM lecturer_phone WHERE lecturer_id = ?')->execute([$id]);
                    $pdo->prepare('DELETE FROM visiting_lecturer WHERE lecturer_id = ?')->execute([$id]);
                    $pdo->prepare('DELETE FROM lecturer_in_charge WHERE lecturer_id = ?')->execute([$id]);
                }

                $ph = $pdo->prepare('INSERT INTO lecturer_phone (lecturer_id, phone_no) VALUES (?, ?)');
                foreach ($phones as $p) {
                    $ph->execute([$id, $p]);
                }

                if ($form['lecturer_type'] === 'Visiting') {
                    $pdo->prepare('INSERT INTO visiting_lecturer (lecturer_id) VALUES (?)')->execute([$id]);
                } elseif ($form['lecturer_type'] === 'In-charge') {
                    $pdo->prepare('INSERT INTO lecturer_in_charge (lecturer_id) VALUES (?)')->execute([$id]);
                }

                $pdo->commit();
                flash_set('success', $editing ? 'Lecturer updated successfully.' : 'Lecturer added successfully.');
                redirect('lecturers.php');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (($ex->errorInfo[1] ?? 0) === 1062) {
                    $errors[] = (strpos($ex->getMessage(), 'email') !== false)
                        ? 'That email address is already used by another lecturer.'
                        : 'A lecturer with that ID already exists.';
                } else {
                    error_log('Lecturer save failed: ' . $ex->getMessage());
                    $errors[] = 'Could not save the lecturer. Please try again.';
                }
            }
        }
    }
}

// ---------- Edit mode: load the lecturer into the form ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $stmt = $pdo->prepare(
        "SELECT l.lecturer_id, l.lecturer_name, l.email,
                CASE WHEN v.lecturer_id IS NOT NULL THEN 'Visiting'
                     WHEN i.lecturer_id IS NOT NULL THEN 'In-charge'
                     ELSE 'Regular' END AS lecturer_type
         FROM lecturer l
         LEFT JOIN visiting_lecturer v ON v.lecturer_id = l.lecturer_id
         LEFT JOIN lecturer_in_charge i ON i.lecturer_id = l.lecturer_id
         WHERE l.lecturer_id = ?");
    $stmt->execute([trim($_GET['edit'])]);
    if ($row = $stmt->fetch()) {
        $ph = $pdo->prepare('SELECT phone_no FROM lecturer_phone WHERE lecturer_id = ? ORDER BY phone_no');
        $ph->execute([$row['lecturer_id']]);
        $form = ['original_id' => $row['lecturer_id'], 'lecturer_id' => $row['lecturer_id'],
                 'lecturer_name' => $row['lecturer_name'], 'email' => $row['email'],
                 'phones' => implode(', ', $ph->fetchAll(PDO::FETCH_COLUMN)),
                 'lecturer_type' => $row['lecturer_type']];
    } else {
        flash_set('error', 'Lecturer not found.');
        redirect('lecturers.php');
    }
}

// ---------- Search / filter / list ----------
$search = trim($_GET['search'] ?? '');
$type   = trim($_GET['type'] ?? '');

$sql = "SELECT * FROM (
          SELECT l.lecturer_id, l.lecturer_name, l.email,
                 (SELECT GROUP_CONCAT(p.phone_no ORDER BY p.phone_no SEPARATOR ', ')
                  FROM lecturer_phone p WHERE p.lecturer_id = l.lecturer_id) AS phones,
                 CASE WHEN v.lecturer_id IS NOT NULL THEN 'Visiting'
                      WHEN i.lecturer_id IS NOT NULL THEN 'In-charge'
                      ELSE 'Regular' END AS lecturer_type
          FROM lecturer l
          LEFT JOIN visiting_lecturer v ON v.lecturer_id = l.lecturer_id
          LEFT JOIN lecturer_in_charge i ON i.lecturer_id = l.lecturer_id
        ) x WHERE 1=1";
$params = [];
if ($search !== '') {
    $sql .= ' AND (lecturer_id LIKE ? OR lecturer_name LIKE ? OR email LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if (in_array($type, ['Regular', 'In-charge', 'Visiting'], true)) {
    $sql .= ' AND lecturer_type = ?';
    $params[] = $type;
}
$sql .= ' ORDER BY lecturer_id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$lecturers = $stmt->fetchAll();

$editing = ($form['original_id'] !== '');

$pageTitle = 'Lecturers';
$active = 'lecturers';
include __DIR__ . '/includes/header.php';
?>
<main>
  <div class="container">
    <h1 class="page-title">Lecturers</h1>
    <p class="page-sub">Manage lecturers, their phone numbers and type.</p>

    <?= flash_render() ?>

    <div class="card">
      <form class="toolbar" method="get" action="lecturers.php">
        <input type="text" name="search" placeholder="Search by ID, name or email" value="<?= e($search) ?>">
        <select name="type">
          <option value="">All types</option>
          <?php foreach (['Regular', 'In-charge', 'Visiting'] as $t): ?>
            <option value="<?= e($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= e($t) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Search</button>
        <a class="btn btn-light" href="lecturers.php">Reset</a>
      </form>

      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>ID</th><th>Name</th><th>Email</th><th>Phone Number(s)</th><th>Type</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (!$lecturers): ?>
            <tr><td colspan="6">No lecturers found.</td></tr>
          <?php endif; ?>
          <?php foreach ($lecturers as $l): ?>
            <tr>
              <td><?= e($l['lecturer_id']) ?></td>
              <td><?= e($l['lecturer_name']) ?></td>
              <td><?= e($l['email']) ?></td>
              <td><?= e($l['phones'] ?? '') ?></td>
              <td><span class="badge <?= $l['lecturer_type'] === 'Regular' ? 'badge-blue' : 'badge-gold' ?>"><?= e($l['lecturer_type']) ?></span></td>
              <td class="actions">
                <a class="btn btn-sm btn-light" href="lecturers.php?edit=<?= urlencode($l['lecturer_id']) ?>">Edit</a>
                <form method="post" action="lecturers.php">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="lecturer_id" value="<?= e($l['lecturer_id']) ?>">
                  <button type="submit" class="btn btn-sm btn-danger"
                          data-confirm="Delete lecturer <?= e($l['lecturer_name']) ?>?">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card section-gap">
      <h3 style="color:var(--navy);margin-bottom:14px"><?= $editing ? 'Edit lecturer' : 'Add a lecturer' ?></h3>
      <?= errors_render($errors) ?>
      <form method="post" action="lecturers.php">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="original_id" value="<?= e($form['original_id']) ?>">
        <div class="form-row">
          <div class="form-group">
            <label for="lecturer_id">Lecturer ID (4 characters)</label>
            <input type="text" id="lecturer_id" name="lecturer_id" maxlength="4" minlength="4" required
                   value="<?= e($form['lecturer_id']) ?>" <?= $editing ? 'readonly' : '' ?>>
          </div>
          <div class="form-group">
            <label for="lecturer_name">Full name</label>
            <input type="text" id="lecturer_name" name="lecturer_name" maxlength="100" required
                   value="<?= e($form['lecturer_name']) ?>">
          </div>
          <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" maxlength="120" required value="<?= e($form['email']) ?>">
          </div>
          <div class="form-group">
            <label for="phones">Phone number(s), separated by commas</label>
            <input type="text" id="phones" name="phones" placeholder="0771234567, 0112345678"
                   value="<?= e($form['phones']) ?>">
          </div>
          <div class="form-group">
            <label for="lecturer_type">Lecturer type</label>
            <select id="lecturer_type" name="lecturer_type" required>
              <?php foreach (['Regular', 'In-charge', 'Visiting'] as $t): ?>
                <option value="<?= e($t) ?>" <?= $form['lecturer_type'] === $t ? 'selected' : '' ?>><?= e($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <button type="submit" class="btn btn-gold"><?= $editing ? 'Update Lecturer' : 'Save Lecturer' ?></button>
        <?php if ($editing): ?><a class="btn btn-light" href="lecturers.php">Cancel</a><?php endif; ?>
      </form>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>