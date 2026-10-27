<?php
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
$pdo = db();

// Fixed list of tables (never from user input).
$counts = [
    'Semesters'         => 'semester',
    'Courses'           => 'course',
    'Lecturers'         => 'lecturer',
    'Demonstrators'     => 'demonstrator',
    'Venues'            => 'venue',
    'Timetable Entries' => 'timetable',
];
$stats = [];
foreach ($counts as $label => $table) {
    $stats[$label] = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}

$theory    = (int)$pdo->query('SELECT COUNT(*) FROM theory_course')->fetchColumn();
$practical = (int)$pdo->query('SELECT COUNT(*) FROM practical_course')->fetchColumn();

// Latest semester (by academic year, then start date)
$latest = $pdo->query('SELECT semester_id, semester_name, academic_year FROM semester
                       ORDER BY academic_year DESC, start_date DESC LIMIT 1')->fetch();

$recent = [];
if ($latest) {
    $q = $pdo->prepare(
        'SELECT t.course_id, c.course_name, l.lecturer_name, t.venue_name,
                t.day_of_week, t.start_time, t.end_time
         FROM timetable t
         JOIN course c ON c.course_id = t.course_id
         JOIN lecturer l ON l.lecturer_id = t.lecturer_id
         WHERE t.semester_id = ?
         ORDER BY FIELD(t.day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"), t.start_time
         LIMIT 8');
    $q->execute([$latest['semester_id']]);
    $recent = $q->fetchAll();
}

$pageTitle = 'Dashboard';
$active = 'dashboard';
include __DIR__ . '/includes/header.php';
?>
<main>
  <div class="container">
    <h1 class="page-title">Dashboard</h1>
    <p class="page-sub">Overview of the semester system.</p>

    <div class="grid grid-4">
      <?php foreach ($stats as $label => $n): ?>
        <div class="card stat"><div class="num"><?= $n ?></div><div class="label"><?= e($label) ?></div></div>
      <?php endforeach; ?>
      <div class="card stat"><div class="num"><?= $theory ?></div><div class="label">Theory Courses</div></div>
      <div class="card stat"><div class="num"><?= $practical ?></div><div class="label">Practical Courses</div></div>
    </div>

    <div class="card section-gap">
      <h3 style="color:var(--navy);margin-bottom:12px">Quick actions</h3>
      <div class="toolbar">
        <a class="btn" href="semesters.php">Manage Semesters</a>
        <a class="btn" href="courses.php">Manage Courses</a>
        <a class="btn" href="lecturers.php">Manage Lecturers</a>
        <a class="btn btn-gold" href="timetable.php">View Timetable</a>
      </div>
    </div>

    <div class="card section-gap">
      <h3 style="color:var(--navy);margin-bottom:12px">
        Timetable preview<?= $latest ? ': ' . e($latest['semester_name']) . ' (' . e($latest['academic_year']) . ')' : '' ?>
      </h3>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Day</th><th>Time</th><th>Course</th><th>Lecturer</th><th>Venue</th></tr></thead>
          <tbody>
          <?php if (!$recent): ?>
            <tr><td colspan="5">No timetable entries for the latest semester.</td></tr>
          <?php endif; ?>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td><?= e($r['day_of_week']) ?></td>
              <td><?= e(substr($r['start_time'], 0, 5)) ?> &ndash; <?= e(substr($r['end_time'], 0, 5)) ?></td>
              <td><?= e($r['course_id']) ?> &ndash; <?= e($r['course_name']) ?></td>
              <td><?= e($r['lecturer_name']) ?></td>
              <td><?= e($r['venue_name']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>