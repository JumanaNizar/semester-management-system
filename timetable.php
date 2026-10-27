<?php
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
$pdo = db();

$days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
$errors = [];
$form = ['timetable_id' => '', 'semester_id' => '', 'pair' => '', 'venue_name' => '',
         'class_type' => 'LECTURE', 'day_of_week' => 'Monday', 'start_time' => '', 'end_time' => ''];

// Dropdown data
$semesters = $pdo->query('SELECT semester_id, semester_name, academic_year FROM semester ORDER BY academic_year DESC, semester_name')->fetchAll();
$venues    = $pdo->query('SELECT venue_name, capacity FROM venue ORDER BY venue_name')->fetchAll();
$pairs     = $pdo->query(
    'SELECT t.course_id, t.lecturer_id, c.course_name, l.lecturer_name
     FROM teaches t
     JOIN course c ON c.course_id = t.course_id
     JOIN lecturer l ON l.lecturer_id = t.lecturer_id
     ORDER BY t.course_id, l.lecturer_name')->fetchAll();

function valid_time(string $t): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $t);
}
function norm_time(string $t): string
{
    return strlen($t) === 5 ? $t . ':00' : $t;
}

// ---------- Handle form submissions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = trim($_POST['timetable_id'] ?? '');
        if (ctype_digit($id)) {
            try {
                $stmt = $pdo->prepare('DELETE FROM timetable WHERE timetable_id = ?');
                $stmt->execute([(int)$id]);
                flash_set('success', $stmt->rowCount() ? 'Timetable entry deleted.' : 'Entry not found.');
            } catch (PDOException $ex) {
                error_log('Timetable delete failed: ' . $ex->getMessage());
                flash_set('error', 'Could not delete the entry. Please try again.');
            }
        } else {
            flash_set('error', 'Invalid entry.');
        }
        redirect('timetable.php');
    }

    if ($action === 'save') {
        foreach (array_keys($form) as $k) {
            $form[$k] = trim($_POST[$k] ?? '');
        }
        $isEdit = ($form['timetable_id'] !== '');
        $ttId = $isEdit && ctype_digit($form['timetable_id']) ? (int)$form['timetable_id'] : 0;
        $courseId = $lecturerId = '';

        // Semester must exist
        $semOk = false;
        if (ctype_digit($form['semester_id'])) {
            $q = $pdo->prepare('SELECT 1 FROM semester WHERE semester_id = ?');
            $q->execute([(int)$form['semester_id']]);
            $semOk = (bool)$q->fetchColumn();
        }
        if (!$semOk) {
            $errors[] = 'Please choose a semester.';
        }

        // Course + lecturer pair must exist in teaches
        $parts = explode('|', $form['pair']);
        if (count($parts) === 2) {
            [$courseId, $lecturerId] = $parts;
            $q = $pdo->prepare('SELECT 1 FROM teaches WHERE course_id = ? AND lecturer_id = ?');
            $q->execute([$courseId, $lecturerId]);
            if (!$q->fetchColumn()) {
                $errors[] = 'That lecturer is not assigned to teach that course.';
            }
        } else {
            $errors[] = 'Please choose a course and lecturer.';
        }

        // Venue must exist
        $venueCap = null;
        $q = $pdo->prepare('SELECT capacity FROM venue WHERE venue_name = ?');
        $q->execute([$form['venue_name']]);
        $venueCap = $q->fetchColumn();
        if ($venueCap === false) {
            $errors[] = 'Please choose a venue.';
        }

        if (!in_array($form['class_type'], ['LECTURE', 'PRACTICAL'], true)) {
            $errors[] = 'Please choose a class type.';
        }
        if (!in_array($form['day_of_week'], $days, true)) {
            $errors[] = 'Please choose a day.';
        }
        if (!valid_time($form['start_time']) || !valid_time($form['end_time'])) {
            $errors[] = 'Please enter valid start and end times.';
        } elseif (norm_time($form['end_time']) <= norm_time($form['start_time'])) {
            $errors[] = 'End time must be after the start time.';
        }

        // Clash checks (only when everything above is valid)
        if (!$errors) {
            $start = norm_time($form['start_time']);
            $end   = norm_time($form['end_time']);
            $sem   = (int)$form['semester_id'];

            $base = 'SELECT t.timetable_id, t.course_id, t.start_time, t.end_time
                     FROM timetable t
                     WHERE t.semester_id = ? AND t.day_of_week = ?
                       AND t.start_time < ? AND ? < t.end_time AND t.timetable_id <> ?';

            $q = $pdo->prepare($base . ' AND t.venue_name = ?');
            $q->execute([$sem, $form['day_of_week'], $end, $start, $ttId, $form['venue_name']]);
            if ($c = $q->fetch()) {
                $errors[] = 'Venue clash: ' . $form['venue_name'] . ' is already used by ' . $c['course_id']
                          . ' from ' . substr($c['start_time'], 0, 5) . ' to ' . substr($c['end_time'], 0, 5) . '.';
            }

            $q = $pdo->prepare($base . ' AND t.lecturer_id = ?');
            $q->execute([$sem, $form['day_of_week'], $end, $start, $ttId, $lecturerId]);
            if ($c = $q->fetch()) {
                $errors[] = 'Lecturer clash: this lecturer already teaches ' . $c['course_id']
                          . ' from ' . substr($c['start_time'], 0, 5) . ' to ' . substr($c['end_time'], 0, 5) . '.';
            }
        }

        if (!$errors) {
            try {
                if (!$isEdit) {
                    $pdo->prepare('INSERT INTO timetable (semester_id, course_id, lecturer_id, venue_name, class_type, day_of_week, start_time, end_time)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$sem, $courseId, $lecturerId, $form['venue_name'], $form['class_type'],
                                   $form['day_of_week'], $start, $end]);
                    $msg = 'Timetable entry added successfully.';
                } else {
                    $pdo->prepare('UPDATE timetable SET semester_id = ?, course_id = ?, lecturer_id = ?, venue_name = ?,
                                   class_type = ?, day_of_week = ?, start_time = ?, end_time = ? WHERE timetable_id = ?')
                        ->execute([$sem, $courseId, $lecturerId, $form['venue_name'], $form['class_type'],
                                   $form['day_of_week'], $start, $end, $ttId]);
                    $msg = 'Timetable entry updated successfully.';
                }

                // Capacity warning (does not block saving)
                $q = $pdo->prepare('SELECT no_of_students FROM course WHERE course_id = ?');
                $q->execute([$courseId]);
                $students = (int)$q->fetchColumn();
                if ($students > (int)$venueCap) {
                    $msg .= ' Warning: ' . $courseId . ' has ' . $students . ' students but ' . $form['venue_name']
                          . ' holds only ' . (int)$venueCap . '.';
                }
                flash_set('success', $msg);
                redirect('timetable.php');
            } catch (PDOException $ex) {
                error_log('Timetable save failed: ' . $ex->getMessage());
                $errors[] = 'Could not save the entry. Please try again.';
            }
        }
    }
}

// ---------- Edit mode ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $eid = trim($_GET['edit']);
    $row = false;
    if (ctype_digit($eid)) {
        $q = $pdo->prepare('SELECT * FROM timetable WHERE timetable_id = ?');
        $q->execute([(int)$eid]);
        $row = $q->fetch();
    }
    if ($row) {
        $form = ['timetable_id' => $row['timetable_id'], 'semester_id' => $row['semester_id'],
                 'pair' => $row['course_id'] . '|' . $row['lecturer_id'], 'venue_name' => $row['venue_name'],
                 'class_type' => $row['class_type'], 'day_of_week' => $row['day_of_week'],
                 'start_time' => substr($row['start_time'], 0, 5), 'end_time' => substr($row['end_time'], 0, 5)];
    } else {
        flash_set('error', 'Timetable entry not found.');
        redirect('timetable.php');
    }
}

// ---------- Filter / list ----------
$fSem    = trim($_GET['semester'] ?? '');
$fDay    = trim($_GET['day'] ?? '');
$fSearch = trim($_GET['search'] ?? '');

$sql = 'SELECT t.timetable_id, s.semester_name, s.academic_year, t.course_id, c.course_name,
               l.lecturer_name, t.venue_name, t.class_type, t.day_of_week, t.start_time, t.end_time
        FROM timetable t
        JOIN semester s ON s.semester_id = t.semester_id
        JOIN course c   ON c.course_id   = t.course_id
        JOIN lecturer l ON l.lecturer_id = t.lecturer_id
        WHERE 1=1';
$params = [];
if ($fSem !== '' && ctype_digit($fSem)) {
    $sql .= ' AND t.semester_id = ?';
    $params[] = (int)$fSem;
}
if (in_array($fDay, $days, true)) {
    $sql .= ' AND t.day_of_week = ?';
    $params[] = $fDay;
}
if ($fSearch !== '') {
    $sql .= ' AND (t.course_id LIKE ? OR c.course_name LIKE ? OR l.lecturer_name LIKE ?)';
    $like = '%' . $fSearch . '%';
    array_push($params, $like, $like, $like);
}
$sql .= ' ORDER BY s.academic_year DESC, s.semester_name, FIELD(t.day_of_week, "' . implode('","', $days) . '"), t.start_time';
$q = $pdo->prepare($sql);
$q->execute($params);
$entries = $q->fetchAll();

$preferred = $pdo->query('SELECT course_id, class_type, venue_name, start_time, end_time
                          FROM preferred_time ORDER BY course_id, class_type, start_time')->fetchAll();

$editing = ($form['timetable_id'] !== '');

$pageTitle = 'Timetable';
$active = 'timetable';
include __DIR__ . '/includes/header.php';
?>
<main>
  <div class="container">
    <h1 class="page-title">Timetable</h1>
    <p class="page-sub">Weekly class schedule by semester, with preferred times.</p>

    <?= flash_render() ?>

    <div class="card">
      <form class="toolbar" method="get" action="timetable.php">
        <select name="semester">
          <option value="">All semesters</option>
          <?php foreach ($semesters as $s): ?>
            <option value="<?= (int)$s['semester_id'] ?>" <?= $fSem === (string)$s['semester_id'] ? 'selected' : '' ?>>
              <?= e($s['semester_name']) ?> (<?= e($s['academic_year']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <select name="day">
          <option value="">All days</option>
          <?php foreach ($days as $d): ?>
            <option <?= $fDay === $d ? 'selected' : '' ?>><?= e($d) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="search" placeholder="Course or lecturer" value="<?= e($fSearch) ?>">
        <button class="btn" type="submit">Filter</button>
        <a class="btn btn-light" href="timetable.php">Reset</a>
      </form>

      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Semester</th><th>Course</th><th>Lecturer</th><th>Venue</th>
                <th>Type</th><th>Day</th><th>Start</th><th>End</th><th>Actions</th></tr>
          </thead>
          <tbody>
          <?php if (!$entries): ?>
            <tr><td colspan="9">No timetable entries found.</td></tr>
          <?php endif; ?>
          <?php foreach ($entries as $t): ?>
            <tr>
              <td><?= e($t['semester_name']) ?> (<?= e($t['academic_year']) ?>)</td>
              <td><?= e($t['course_id']) ?> &ndash; <?= e($t['course_name']) ?></td>
              <td><?= e($t['lecturer_name']) ?></td>
              <td><?= e($t['venue_name']) ?></td>
              <td><span class="badge <?= $t['class_type'] === 'PRACTICAL' ? 'badge-gold' : 'badge-blue' ?>"><?= e($t['class_type']) ?></span></td>
              <td><?= e($t['day_of_week']) ?></td>
              <td><?= e(substr($t['start_time'], 0, 5)) ?></td>
              <td><?= e(substr($t['end_time'], 0, 5)) ?></td>
              <td class="actions">
                <a class="btn btn-sm btn-light" href="timetable.php?edit=<?= (int)$t['timetable_id'] ?>">Edit</a>
                <form method="post" action="timetable.php">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="timetable_id" value="<?= (int)$t['timetable_id'] ?>">
                  <button type="submit" class="btn btn-sm btn-danger"
                          data-confirm="Delete this timetable entry?">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card section-gap">
      <h3 style="color:var(--navy);margin-bottom:14px"><?= $editing ? 'Edit timetable entry' : 'Add a timetable entry' ?></h3>
      <?= errors_render($errors) ?>
      <form method="post" action="timetable.php">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="timetable_id" value="<?= e($form['timetable_id']) ?>">
        <div class="form-row">
          <div class="form-group">
            <label for="semester_id">Semester</label>
            <select id="semester_id" name="semester_id" required>
              <option value="">Select semester</option>
              <?php foreach ($semesters as $s): ?>
                <option value="<?= (int)$s['semester_id'] ?>" <?= (string)$form['semester_id'] === (string)$s['semester_id'] ? 'selected' : '' ?>>
                  <?= e($s['semester_name']) ?> (<?= e($s['academic_year']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="pair">Course and lecturer</label>
            <select id="pair" name="pair" required>
              <option value="">Select course and lecturer</option>
              <?php foreach ($pairs as $p): $val = $p['course_id'] . '|' . $p['lecturer_id']; ?>
                <option value="<?= e($val) ?>" <?= $form['pair'] === $val ? 'selected' : '' ?>>
                  <?= e($p['course_id']) ?> &ndash; <?= e($p['lecturer_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="venue_name">Venue</label>
            <select id="venue_name" name="venue_name" required>
              <option value="">Select venue</option>
              <?php foreach ($venues as $v): ?>
                <option value="<?= e($v['venue_name']) ?>" <?= $form['venue_name'] === $v['venue_name'] ? 'selected' : '' ?>>
                  <?= e($v['venue_name']) ?> (capacity <?= e($v['capacity']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="class_type">Class type</label>
            <select id="class_type" name="class_type" required>
              <option value="LECTURE" <?= $form['class_type'] === 'LECTURE' ? 'selected' : '' ?>>Lecture</option>
              <option value="PRACTICAL" <?= $form['class_type'] === 'PRACTICAL' ? 'selected' : '' ?>>Practical</option>
            </select>
          </div>
          <div class="form-group">
            <label for="day_of_week">Day</label>
            <select id="day_of_week" name="day_of_week" required>
              <?php foreach ($days as $d): ?>
                <option <?= $form['day_of_week'] === $d ? 'selected' : '' ?>><?= e($d) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="start_time">Start time</label>
            <input type="time" id="start_time" name="start_time" required value="<?= e($form['start_time']) ?>">
          </div>
          <div class="form-group">
            <label for="end_time">End time</label>
            <input type="time" id="end_time" name="end_time" required value="<?= e($form['end_time']) ?>">
          </div>
        </div>
        <button type="submit" class="btn btn-gold"><?= $editing ? 'Update Entry' : 'Save Entry' ?></button>
        <?php if ($editing): ?><a class="btn btn-light" href="timetable.php">Cancel</a><?php endif; ?>
      </form>
    </div>

    <div class="card section-gap">
      <h3 style="color:var(--navy);margin-bottom:14px">Preferred times</h3>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Course</th><th>Type</th><th>Venue</th><th>Start</th><th>End</th></tr></thead>
          <tbody>
          <?php if (!$preferred): ?>
            <tr><td colspan="5">No preferred times recorded.</td></tr>
          <?php endif; ?>
          <?php foreach ($preferred as $p): ?>
            <tr>
              <td><?= e($p['course_id']) ?></td>
              <td><?= e($p['class_type']) ?></td>
              <td><?= e($p['venue_name']) ?></td>
              <td><?= e(substr($p['start_time'], 0, 5)) ?></td>
              <td><?= e(substr($p['end_time'], 0, 5)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>