<?php
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
$pdo = db();

$errors = [];
$form = ['semester_id' => '', 'semester_name' => '', 'academic_year' => '', 'start_date' => '', 'end_date' => ''];

function valid_date(string $d): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

// ---------- Handle form submissions (add / update / delete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = trim($_POST['semester_id'] ?? '');
        if (ctype_digit($id)) {
            try {
                $stmt = $pdo->prepare('DELETE FROM semester WHERE semester_id = ?');
                $stmt->execute([(int)$id]);
                flash_set('success', $stmt->rowCount() ? 'Semester deleted (including its timetable entries).' : 'Semester not found.');
            } catch (PDOException $ex) {
                error_log('Semester delete failed: ' . $ex->getMessage());
                flash_set('error', 'Could not delete the semester. Please try again.');
            }
        } else {
            flash_set('error', 'Invalid semester.');
        }
        redirect('semesters.php');
    }

    if ($action === 'save') {
        foreach (array_keys($form) as $k) {
            $form[$k] = trim($_POST[$k] ?? '');
        }

        if ($form['semester_name'] === '' || mb_strlen($form['semester_name']) > 20) {
            $errors[] = 'Semester name is required and must be 20 characters or fewer.';
        }
        if (!ctype_digit($form['academic_year']) || (int)$form['academic_year'] < 2000 || (int)$form['academic_year'] > 2100) {
            $errors[] = 'Academic year must be between 2000 and 2100.';
        }
        if (!valid_date($form['start_date'])) {
            $errors[] = 'Start date is not a valid date.';
        }
        if (!valid_date($form['end_date'])) {
            $errors[] = 'End date is not a valid date.';
        }
        if (!$errors && $form['end_date'] < $form['start_date']) {
            $errors[] = 'End date cannot be before the start date.';
        }

        if (!$errors) {
            try {
                if ($form['semester_id'] === '') {
                    $stmt = $pdo->prepare('INSERT INTO semester (semester_name, academic_year, start_date, end_date) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$form['semester_name'], (int)$form['academic_year'], $form['start_date'], $form['end_date']]);
                    flash_set('success', 'Semester added successfully.');
                } elseif (ctype_digit($form['semester_id'])) {
                    $stmt = $pdo->prepare('UPDATE semester SET semester_name = ?, academic_year = ?, start_date = ?, end_date = ? WHERE semester_id = ?');
                    $stmt->execute([$form['semester_name'], (int)$form['academic_year'], $form['start_date'], $form['end_date'], (int)$form['semester_id']]);
                    flash_set('success', 'Semester updated successfully.');
                } else {
                    throw new RuntimeException('Bad semester id');
                }
                redirect('semesters.php');
            } catch (PDOException $ex) {
                if (($ex->errorInfo[1] ?? 0) === 1062) {
                    $errors[] = 'That semester name already exists for that academic year.';
                } else {
                    error_log('Semester save failed: ' . $ex->getMessage());
                    $errors[] = 'Could not save the semester. Please try again.';
                }
            }
        }
    }
}

// ---------- Edit mode: load the semester into the form ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $eid = trim($_GET['edit']);
    $row = false;
    if (ctype_digit($eid)) {
        $stmt = $pdo->prepare('SELECT semester_id, semester_name, academic_year, start_date, end_date FROM semester WHERE semester_id = ?');
        $stmt->execute([(int)$eid]);
        $row = $stmt->fetch();
    }
    if ($row) {
        $form = $row;
    } else {
        flash_set('error', 'Semester not found.');
        redirect('semesters.php');
    }
}

// ---------- Search / list ----------
$search = trim($_GET['search'] ?? '');
$year   = trim($_GET['year'] ?? '');

$sql = 'SELECT semester_id, semester_name, academic_year, start_date, end_date FROM semester WHERE 1=1';
$params = [];
if ($search !== '') {
    $sql .= ' AND semester_name LIKE ?';
    $params[] = '%' . $search . '%';
}
if ($year !== '' && ctype_digit($year)) {
    $sql .= ' AND academic_year = ?';
    $params[] = (int)$year;
}
$sql .= ' ORDER BY academic_year DESC, semester_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$semesters = $stmt->fetchAll();

$editing = ($form['semester_id'] !== '');

$pageTitle = 'Semesters';
$active = 'semesters';
include __DIR__ . '/includes/header.php';
?>
<main>
  <div class="container">
    <h1 class="page-title">Semesters</h1>
    <p class="page-sub">Add, search, edit and remove academic semesters.</p>

    <?= flash_render() ?>

    <div class="card">
      <form class="toolbar" method="get" action="semesters.php">
        <input type="text" name="search" placeholder="Search by semester name" value="<?= e($search) ?>">
        <input type="number" name="year" placeholder="Academic year" value="<?= e($year) ?>">
        <button class="btn" type="submit">Search</button>
        <a class="btn btn-light" href="semesters.php">Reset</a>
      </form>

      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>ID</th><th>Semester Name</th><th>Academic Year</th>
                <th>Start Date</th><th>End Date</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (!$semesters): ?>
            <tr><td colspan="6">No semesters found.</td></tr>
          <?php endif; ?>
          <?php foreach ($semesters as $s): ?>
            <tr>
              <td><?= e($s['semester_id']) ?></td>
              <td><?= e($s['semester_name']) ?></td>
              <td><?= e($s['academic_year']) ?></td>
              <td><?= e($s['start_date']) ?></td>
              <td><?= e($s['end_date']) ?></td>
              <td class="actions">
                <a class="btn btn-sm btn-light" href="semesters.php?edit=<?= (int)$s['semester_id'] ?>">Edit</a>
                <form method="post" action="semesters.php">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="semester_id" value="<?= (int)$s['semester_id'] ?>">
                  <button type="submit" class="btn btn-sm btn-danger"
                          data-confirm="Delete this semester? All its timetable entries will also be deleted.">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card section-gap">
      <h3 style="color:var(--navy);margin-bottom:14px"><?= $editing ? 'Edit semester' : 'Add a semester' ?></h3>
      <?= errors_render($errors) ?>
      <form method="post" action="semesters.php">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="semester_id" value="<?= e($form['semester_id']) ?>">
        <div class="form-row">
          <div class="form-group">
            <label for="semester_name">Semester name</label>
            <input type="text" id="semester_name" name="semester_name" maxlength="20" required
                   value="<?= e($form['semester_name']) ?>">
          </div>
          <div class="form-group">
            <label for="academic_year">Academic year</label>
            <input type="number" id="academic_year" name="academic_year" min="2000" max="2100" required
                   value="<?= e($form['academic_year']) ?>">
          </div>
          <div class="form-group">
            <label for="start_date">Start date</label>
            <input type="date" id="start_date" name="start_date" required value="<?= e($form['start_date']) ?>">
          </div>
          <div class="form-group">
            <label for="end_date">End date</label>
            <input type="date" id="end_date" name="end_date" required value="<?= e($form['end_date']) ?>">
          </div>
        </div>
        <button type="submit" class="btn btn-gold"><?= $editing ? 'Update Semester' : 'Save Semester' ?></button>
        <?php if ($editing): ?><a class="btn btn-light" href="semesters.php">Cancel</a><?php endif; ?>
      </form>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>