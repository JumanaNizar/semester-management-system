<?php
$pageTitle = 'Home';
$active = 'home';
include 'includes/header.php';
?>
<section class="hero">
  <div class="container">
    <h1>Semester Management System</h1>
    <p>Manage semesters, courses, lecturers, demonstrators, venues and weekly timetables in one place.</p>
    <a class="btn btn-gold" href="login.php">Login to Continue</a>
  </div>
</section>

<main>
  <div class="container">
    <div class="grid grid-3">
      <div class="card feature"><h3>Semesters &amp; Courses</h3>
        <p>Create academic semesters and manage theory and practical courses.</p></div>
      <div class="card feature"><h3>Lecturers &amp; Demonstrators</h3>
        <p>Keep staff details, phone numbers and course assignments up to date.</p></div>
      <div class="card feature"><h3>Venues &amp; Timetable</h3>
        <p>Schedule classes by day and time with venue capacity in mind.</p></div>
    </div>
  </div>
</main>
<?php include 'includes/footer.php'; ?>