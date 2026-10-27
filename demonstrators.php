<?php
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
$pdo = db();

$errors = [];
$form = ['original_id' => '', 'demonstrator_id' => '', 'demonstrator_name' => '',
         'email' => '', 'phone_no' => ''];
$selectedCourses = [];

// All practical courses (the only ones a demonstrator can be assigned to).
$practical = $pdo->query(
    'SELECT c.course_id, c.course_name
     FROM practical_course p JOIN course c ON c.course_id = p.course_id
     ORDER BY c.course_id')->fetchAll();
$validCourseIds = array_column($practical, 'course_id');

// ---------- Handle form submissions (add / update / delete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = trim($_POST['demonstrator_id'] ?? '');
        try {
            $stmt = $pdo->prepare('DELETE FROM demonstrator WHERE demonstrator_id = ?');
            $stmt->execute([$id]);
            flash_set('success', $stmt->rowCount() ? 'Demonstrator deleted.' : 'Demonstrator not found.');
        } catch (PDOException $ex) {
            error_log('Demonstrator delete failed: ' . $ex->getMessage());
            flash_set('error', 'Could not delete the demonstrator. Please try again.');
        }
        redirect('demonstrators.php');
    }

    if ($action === 'save') {
        foreach (array_keys($form) as $k) {
            $form[$k] = trim($_POST[$k] ?? '');
        }
        $editing = ($form['original_id'] !== '');
        if ($editing) {
            $form['demonstrator_id'] = $form['original_id'];   // ID cannot be changed
        }

        $posted = $_POST['courses'] ?? [];
        $selectedCourses = is_array($posted) ? array_values(array_unique(array_map('strval', $posted))) : [];

        if (!preg_match('/^[A-Za-z0-9]{4}$/', $form['demonstrator_id'])) {
            $errors[] = 'Demonstrator ID must be exactly 4 letters or digits.';
        }
        if ($form['demonstrator_name'] === '' || mb_strlen($form['demonstrator_name']) > 100) {
            $errors[] = 'Name is required and must be 100 characters or fewer.';
        }
        if (mb_strlen($form['email']) > 120 || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address (120 characters or fewer).';
        }
        if (!preg_match('/^[0-9+\- ]{5,15}$/', $form['phone_no'])) {
            $errors[] = 'Phone number must be 5 to 15 characters (digits, + or - allowed).';
        }
        foreach ($selectedCourses as $cid) {
            if (!in_array($cid, $validCourseIds, true)) {
                $errors[] = 'One of the selected courses is not a practical course.';
                break;
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $id = $form['demonstrator_id'];

                if (!$editing) {
                    $pdo->prepare('INSERT INTO demonstrator (demonstrator_id, demonstrator_name, email, phone_no) VALUES (?, ?, ?, ?)')
                        ->execute([$id, $form['demonstrator_name'], $form['email'], $form['phone_no']]);
                } else {
                    $pdo->prepare('UPDATE demonstrator SET demonstrator_name = ?, email = ?, phone_no = ? WHERE demonstrator_id = ?')
                        ->execute([$form['demonstrator_name'], $form['email'], $form['phone_no'], $id]);
                    $pdo->prepare('DELETE FROM course_demonstrator_incharge WHERE demonstrator_id = ?')->execute([$id]);
                }

                $link = $pdo->prepare('INSERT INTO course_demonstrator_incharge (course_id, demonstrator_id) VALUES (?, ?)');
                foreach ($selectedCourses as $cid) {
                    $link->execute([$cid, $id]);
                }

                $pdo->commit();
                flash_set('success', $editing ? 'Demonstrator updated successfully.' : 'Demonstrator added successfully.');
                redirect('demonstrators.php');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (($ex->errorInfo[1] ?? 0) === 1062) {
                    $errors[] = (strpos($ex->getMessage(), 'email') !== false)
                        ? 'That email address is already used by another demonstrator.'
                        : 'A demonstrator with that ID already exists.';
                } else {
                    error_log('Demonstrator save failed: ' . $ex->getMessage());
                    $errors[] = 'Could not save the demonstrator. Please try again.';
                }
            }
        }
    }
}

// ---------- Edit mode: load the demonstrator into the form ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT demonstrator_id, demonstrator_name, email, phone_no FROM demonstrator WHERE demonstrator_id = ?');
    $stmt->execute([trim($_GET['edit'])]);
    if ($row = $stmt->fetch()) {
        $form = ['original_id' => $row['demonstrator_id'], 'demonstrator_id' => $row['demonstrator_id'],
                 'demonstrator_name' => $row['demonstrator_name'], 'email' => $row['email'],
                 'phone_no' => $row['phone_no']];
        $cs = $pdo->prepare('SELECT course_id FROM course_demonstrator_incharge WHERE demonstrator_id = ?');
        $cs->execute([$row['demonstrator_id']]);
        $selectedCourses = $cs->fetchAll(PDO::FETCH_COLUMN);
    } else {
        flash_set('error', 'Demonstrator not found.');
        redirect('demonstrators.php');
    }
}

// ---------- Search / list ----------
$search = trim($_GET['search'] ?? '');

$sql = "SELECT d.demonstrator_id, d.demonstrator_name, d.email, d.phone_no,
               (SELECT GROUP_CONCAT(x.course_id ORDER BY x.course_id SEPARATOR ', ')
                FROM course_demonstrator_incharge x WHERE x.demonstrator_id = d.demonstrator_id) AS courses
        FROM demonstrator d WHERE 1=1";
$params = [];
if ($search !== '') {
    $sql .= ' AND (d.demonstrator_id LIKE ? OR d.demonstrator_name LIKE ? OR d.email LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$sql .= ' ORDER BY d.demonstrator_id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$demonstrators = $stmt->fetchAll();

$editing = ($form['original_id'] !== '');

$pageTitle = 'Demonstrators';
$active = 'demonstrators';
include __DIR__ . '/includes/header.php';
?>
<main>
  <div class="container">
    <h1 class="page-title">Demonstrators</h1>
    <p class="page-sub">Manage demonstrators and the practical courses they are in charge of.</p>

    <?= flash_render() ?>

    <div class="card">
      <form class="toolbar" method="get" action="demonstrators.php">
        <input type="text" name="search" placeholder="Search by ID, name or email" value="<?= e($search) ?>">
        <button class="btn" type="submit">Search</button>
        <a class="btn btn-light" href="demonstrators.php">Reset</a>
      </form>

      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Practical Courses</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (!$demonstrators): ?>
            <tr><td colspan="6">No demonstrators found.</td></tr>
          <?php endif; ?>
          <?php foreach ($demonstrators as $d): ?>
            <tr>
              <td><?= e($d['demonstrator_id']) ?></td>
              <td><?= e($d['demonstrator_name']) ?></td>
              <td><?= e($d['email']) ?></td>
              <td><?= e($d['phone_no']) ?></td>
              <td><?= e($d['courses'] ?? '') ?></td>
              <td class="actions">
                <a class="btn btn-sm btn-light" href="demonstrators.php?edit=<?= urlencode($d['demonstrator_id']) ?>">Edit</a>
                <form method="post" action="demonstrators.php">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="demonstrator_id" value="<?= e($d['demonstrator_id']) ?>">
                  <button type="submit" class="btn btn-sm btn-danger"
                          data-confirm="Delete <?= e($d['demonstrator_name']) ?>? Their course assignments will also be removed.">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card section-gap">
      <h3 style="color:var(--navy);margin-bottom:14px"><?= $editing ? 'Edit demonstrator' : 'Add a demonstrator' ?></h3>
      <?= errors_render($errors) ?>
      <form method="post" action="demonstrators.php">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="original_id" value="<?= e($form['original_id']) ?>">
        <div class="form-row">
          <div class="form-group">
            <label for="demonstrator_id">Demonstrator ID (4 characters)</label>
            <input type="text" id="demonstrator_id" name="demonstrator_id" maxlength="4" minlength="4" required
                   value="<?= e($form['demonstrator_id']) ?>" <?= $editing ? 'readonly' : '' ?>>
          </div>
          <div class="form-group">
            <label for="demonstrator_name">Full name</label>
            <input type="text" id="demonstrator_name" name="demonstrator_name" maxlength="100" required
                   value="<?= e($form['demonstrator_name']) ?>">
          </div>
          <div class="form-group">
            <label for="d_email">Email</label>
            <input type="email" id="d_email" name="email" maxlength="120" required value="<?= e($form['email']) ?>">
          </div>
          <div class="form-group">
            <label for="d_phone">Phone number</label>
            <input type="text" id="d_phone" name="phone_no" maxlength="15" required value="<?= e($form['phone_no']) ?>">
          </div>
        </div>

        <div class="form-group">
          <label>Practical courses in charge</label>
          <?php if (!$practical): ?>
            <p class="page-sub" style="margin:0">There are no practical courses yet.</p>
          <?php endif; ?>
          <?php foreach ($practical as $pc): ?>
            <label style="font-weight:400;display:block;margin-bottom:4px">
              <input type="checkbox" name="courses[]" value="<?= e($pc['course_id']) ?>"
                     style="width:auto;margin-right:8px"
                     <?= in_array($pc['course_id'], $selectedCourses, true) ? 'checked' : '' ?>>
              <?= e($pc['course_id']) ?> &ndash; <?= e($pc['course_name']) ?>
            </label>
          <?php endforeach; ?>
        </div>

        <button type="submit" class="btn btn-gold"><?= $editing ? 'Update Demonstrator' : 'Save Demonstrator' ?></button>
        <?php if ($editing): ?><a class="btn btn-light" href="demonstrators.php">Cancel</a><?php endif; ?>
      </form>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
