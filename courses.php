<?php
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
$pdo = db();

$errors = [];
$form = ['original_id' => '', 'course_id' => '', 'course_name' => '', 'no_of_students' => '', 'course_type' => ''];

// ---------- Handle form submissions (add / update / delete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = trim($_POST['course_id'] ?? '');
        try {
            $stmt = $pdo->prepare('DELETE FROM course WHERE course_id = ?');
            $stmt->execute([$id]);
            flash_set('success', $stmt->rowCount() ? 'Course deleted.' : 'Course not found.');
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                flash_set('error', 'Cannot delete this course because it is used in the timetable. Remove its timetable entries first.');
            } else {
                error_log('Course delete failed: ' . $ex->getMessage());
                flash_set('error', 'Could not delete the course. Please try again.');
            }
        }
        redirect('courses.php');
    }

    if ($action === 'save') {
        foreach (array_keys($form) as $k) {
            $form[$k] = trim($_POST[$k] ?? '');
        }
        $editing = ($form['original_id'] !== '');
        if ($editing) {
            $form['course_id'] = $form['original_id'];   // ID cannot be changed
        }

        if (!preg_match('/^[A-Za-z0-9]{1,7}$/', $form['course_id'])) {
            $errors[] = 'Course ID must be 1 to 7 letters or digits (no spaces).';
        }
        if ($form['course_name'] === '' || mb_strlen($form['course_name']) > 150) {
            $errors[] = 'Course name is required and must be 150 characters or fewer.';
        }
        if (!ctype_digit($form['no_of_students']) || (int)$form['no_of_students'] > 100000) {
            $errors[] = 'Number of students must be a whole number from 0 to 100000.';
        }
        if (!in_array($form['course_type'], ['THEORY', 'PRACTICAL'], true)) {
            $errors[] = 'Please choose a course type.';
        }

        if (!$errors) {
            // Table names come from this fixed list, never from user input.
            $typeTable  = ($form['course_type'] === 'THEORY') ? 'theory_course' : 'practical_course';
            $otherTable = ($form['course_type'] === 'THEORY') ? 'practical_course' : 'theory_course';
            try {
                $pdo->beginTransaction();
                if (!$editing) {
                    $stmt = $pdo->prepare('INSERT INTO course (course_id, course_name, no_of_students) VALUES (?, ?, ?)');
                    $stmt->execute([$form['course_id'], $form['course_name'], (int)$form['no_of_students']]);
                    $pdo->prepare("INSERT INTO $typeTable (course_id) VALUES (?)")->execute([$form['course_id']]);
                } else {
                    $stmt = $pdo->prepare('UPDATE course SET course_name = ?, no_of_students = ? WHERE course_id = ?');
                    $stmt->execute([$form['course_name'], (int)$form['no_of_students'], $form['course_id']]);
                    $pdo->prepare("DELETE FROM $otherTable WHERE course_id = ?")->execute([$form['course_id']]);
                    $pdo->prepare("INSERT IGNORE INTO $typeTable (course_id) VALUES (?)")->execute([$form['course_id']]);
                }
                $pdo->commit();
                flash_set('success', $editing ? 'Course updated successfully.' : 'Course added successfully.');
                redirect('courses.php');
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (($ex->errorInfo[1] ?? 0) === 1062) {
                    $errors[] = 'A course with that ID already exists.';
                } else {
                    error_log('Course save failed: ' . $ex->getMessage());
                    $errors[] = 'Could not save the course. Please try again.';
                }
            }
        }
    }
}

// ---------- Edit mode: load the course into the form ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $stmt = $pdo->prepare(
        "SELECT c.course_id, c.course_name, c.no_of_students,
                IF(p.course_id IS NOT NULL, 'PRACTICAL', 'THEORY') AS course_type
         FROM course c LEFT JOIN practical_course p ON p.course_id = c.course_id
         WHERE c.course_id = ?");
    $stmt->execute([trim($_GET['edit'])]);
    if ($row = $stmt->fetch()) {
        $form = ['original_id' => $row['course_id'], 'course_id' => $row['course_id'],
                 'course_name' => $row['course_name'], 'no_of_students' => $row['no_of_students'],
                 'course_type' => $row['course_type']];
    } else {
        flash_set('error', 'Course not found.');
        redirect('courses.php');
    }
}

// ---------- Search / filter / list ----------
$search = trim($_GET['search'] ?? '');
$type   = trim($_GET['type'] ?? '');

$sql = "SELECT c.course_id, c.course_name, c.no_of_students,
               IF(p.course_id IS NOT NULL, 'PRACTICAL', 'THEORY') AS course_type
        FROM course c
        LEFT JOIN practical_course p ON p.course_id = c.course_id
        WHERE 1=1";
$params = [];
if ($search !== '') {
    $sql .= ' AND (c.course_id LIKE ? OR c.course_name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($type === 'PRACTICAL') {
    $sql .= ' AND p.course_id IS NOT NULL';
} elseif ($type === 'THEORY') {
    $sql .= ' AND p.course_id IS NULL';
}
$sql .= ' ORDER BY c.course_id';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$courses = $stmt->fetchAll();

$editing = ($form['original_id'] !== '');

$pageTitle = 'Courses';
$active = 'courses';
include __DIR__ . '/includes/header.php';
?>
<main>
  <div class="container">
    <h1 class="page-title">Courses</h1>
    <p class="page-sub">Manage theory and practical courses.</p>

    <?= flash_render() ?>

    <div class="card">
      <form class="toolbar" method="get" action="courses.php">
        <input type="text" name="search" placeholder="Search by course ID or name" value="<?= e($search) ?>">
        <select name="type">
          <option value="">All types</option>
          <option value="THEORY" <?= $type === 'THEORY' ? 'selected' : '' ?>>Theory</option>
          <option value="PRACTICAL" <?= $type === 'PRACTICAL' ? 'selected' : '' ?>>Practical</option>
        </select>
        <button class="btn" type="submit">Search</button>
        <a class="btn btn-light" href="courses.php">Reset</a>
      </form>

      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Course ID</th><th>Course Name</th><th>No. of Students</th><th>Type</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (!$courses): ?>
            <tr><td colspan="5">No courses found.</td></tr>
          <?php endif; ?>
          <?php foreach ($courses as $c): ?>
            <tr>
              <td><?= e($c['course_id']) ?></td>
              <td><?= e($c['course_name']) ?></td>
              <td><?= e($c['no_of_students']) ?></td>
              <td><span class="badge <?= $c['course_type'] === 'PRACTICAL' ? 'badge-gold' : 'badge-blue' ?>"><?= e($c['course_type']) ?></span></td>
              <td class="actions">
                <a class="btn btn-sm btn-light" href="courses.php?edit=<?= urlencode($c['course_id']) ?>">Edit</a>
                <form method="post" action="courses.php">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="course_id" value="<?= e($c['course_id']) ?>">
                  <button type="submit" class="btn btn-sm btn-danger"
                          data-confirm="Delete course <?= e($c['course_id']) ?>?">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card section-gap">
      <h3 style="color:var(--navy);margin-bottom:14px"><?= $editing ? 'Edit course' : 'Add a course' ?></h3>
      <?= errors_render($errors) ?>
      <?php if ($editing): ?>
        <div class="note">Changing a course from Practical to Theory also removes its demonstrator assignments.</div>
      <?php endif; ?>
      <form method="post" action="courses.php">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="original_id" value="<?= e($form['original_id']) ?>">
        <div class="form-row">
          <div class="form-group">
            <label for="course_id">Course ID</label>
            <input type="text" id="course_id" name="course_id" maxlength="7" required
                   value="<?= e($form['course_id']) ?>" <?= $editing ? 'readonly' : '' ?>>
          </div>
          <div class="form-group">
            <label for="course_name">Course name</label>
            <input type="text" id="course_name" name="course_name" maxlength="150" required
                   value="<?= e($form['course_name']) ?>">
          </div>
          <div class="form-group">
            <label for="no_of_students">Number of students</label>
            <input type="number" id="no_of_students" name="no_of_students" min="0" required
                   value="<?= e($form['no_of_students']) ?>">
          </div>
          <div class="form-group">
            <label for="course_type">Course type</label>
            <select id="course_type" name="course_type" required>
              <option value="">Select type</option>
              <option value="THEORY" <?= $form['course_type'] === 'THEORY' ? 'selected' : '' ?>>Theory</option>
              <option value="PRACTICAL" <?= $form['course_type'] === 'PRACTICAL' ? 'selected' : '' ?>>Practical</option>
            </select>
                    </div>
        </div>
        <button type="submit" class="btn btn-gold"><?= $editing ? 'Update Course' : 'Save Course' ?></button>
        <?php if ($editing): ?><a class="btn btn-light" href="courses.php">Cancel</a><?php endif; ?>
      </form>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>