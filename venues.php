<?php
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/db.php';
$pdo = db();

$errors = [];
$form = ['original_name' => '', 'venue_name' => '', 'capacity' => ''];

// ---------- Handle form submissions (add / update / delete) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $name = trim($_POST['venue_name'] ?? '');
        try {
            $stmt = $pdo->prepare('DELETE FROM venue WHERE venue_name = ?');
            $stmt->execute([$name]);
            flash_set('success', $stmt->rowCount() ? 'Venue deleted.' : 'Venue not found.');
        } catch (PDOException $ex) {
            if ($ex->getCode() === '23000') {
                flash_set('error', 'Cannot delete this venue because it is used in the timetable or preferred times. Remove those first.');
            } else {
                error_log('Venue delete failed: ' . $ex->getMessage());
                flash_set('error', 'Could not delete the venue. Please try again.');
            }
        }
        redirect('venues.php');
    }

    if ($action === 'save') {
        $form['original_name'] = trim($_POST['original_name'] ?? '');
        $form['venue_name']    = trim($_POST['venue_name'] ?? '');
        $form['capacity']      = trim($_POST['capacity'] ?? '');

        if ($form['venue_name'] === '' || mb_strlen($form['venue_name']) > 30) {
            $errors[] = 'Venue name is required and must be 30 characters or fewer.';
        }
        if (!ctype_digit($form['capacity']) || (int)$form['capacity'] > 100000) {
            $errors[] = 'Capacity must be a whole number from 0 to 100000.';
        }

        if (!$errors) {
            try {
                if ($form['original_name'] === '') {
                    $stmt = $pdo->prepare('INSERT INTO venue (venue_name, capacity) VALUES (?, ?)');
                    $stmt->execute([$form['venue_name'], (int)$form['capacity']]);
                    flash_set('success', 'Venue added successfully.');
                } else {
                    $stmt = $pdo->prepare('UPDATE venue SET venue_name = ?, capacity = ? WHERE venue_name = ?');
                    $stmt->execute([$form['venue_name'], (int)$form['capacity'], $form['original_name']]);
                    flash_set('success', 'Venue updated successfully.');
                }
                redirect('venues.php');
            } catch (PDOException $ex) {
                if (($ex->errorInfo[1] ?? 0) === 1062) {
                    $errors[] = 'A venue with that name already exists.';
                } else {
                    error_log('Venue save failed: ' . $ex->getMessage());
                    $errors[] = 'Could not save the venue. Please try again.';
                }
            }
        }
    }
}

// ---------- Edit mode: load the venue into the form ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT venue_name, capacity FROM venue WHERE venue_name = ?');
    $stmt->execute([trim($_GET['edit'])]);
    if ($row = $stmt->fetch()) {
        $form = ['original_name' => $row['venue_name'], 'venue_name' => $row['venue_name'], 'capacity' => $row['capacity']];
    } else {
        flash_set('error', 'Venue not found.');
        redirect('venues.php');
    }
}

// ---------- Search / list ----------
$search = trim($_GET['search'] ?? '');
$minCap = trim($_GET['min_capacity'] ?? '');

$sql = 'SELECT venue_name, capacity FROM venue WHERE 1=1';
$params = [];
if ($search !== '') {
    $sql .= ' AND venue_name LIKE ?';
    $params[] = '%' . $search . '%';
}
if ($minCap !== '' && ctype_digit($minCap)) {
    $sql .= ' AND capacity >= ?';
    $params[] = (int)$minCap;
}
$sql .= ' ORDER BY venue_name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$venues = $stmt->fetchAll();

$editing = ($form['original_name'] !== '');

$pageTitle = 'Venues';
$active = 'venues';
include __DIR__ . '/includes/header.php';
?>
<main>
  <div class="container">
    <h1 class="page-title">Venues</h1>
    <p class="page-sub">Lecture halls and labs with their capacity.</p>

    <?= flash_render() ?>

    <div class="card">
      <form class="toolbar" method="get" action="venues.php">
        <input type="text" name="search" placeholder="Search by venue name" value="<?= e($search) ?>">
        <input type="number" name="min_capacity" placeholder="Minimum capacity" min="0" value="<?= e($minCap) ?>">
        <button class="btn" type="submit">Search</button>
        <a class="btn btn-light" href="venues.php">Reset</a>
      </form>

      <div class="table-wrap">
        <table>
          <thead><tr><th>Venue Name</th><th>Capacity</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if (!$venues): ?>
            <tr><td colspan="3">No venues found.</td></tr>
          <?php endif; ?>
          <?php foreach ($venues as $v): ?>
            <tr>
              <td><?= e($v['venue_name']) ?></td>
              <td><?= e($v['capacity']) ?></td>
              <td class="actions">
                <a class="btn btn-sm btn-light" href="venues.php?edit=<?= urlencode($v['venue_name']) ?>">Edit</a>
                <form method="post" action="venues.php">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="venue_name" value="<?= e($v['venue_name']) ?>">
                  <button type="submit" class="btn btn-sm btn-danger"
                          data-confirm="Delete venue <?= e($v['venue_name']) ?>?">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card section-gap">
      <h3 style="color:var(--navy);margin-bottom:14px"><?= $editing ? 'Edit venue' : 'Add a venue' ?></h3>
      <?= errors_render($errors) ?>
      <form method="post" action="venues.php">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="original_name" value="<?= e($form['original_name']) ?>">
        <div class="form-row">
          <div class="form-group">
            <label for="venue_name">Venue name</label>
            <input type="text" id="venue_name" name="venue_name" maxlength="30" required
                   value="<?= e($form['venue_name']) ?>">
          </div>
          <div class="form-group">
            <label for="capacity">Capacity</label>
            <input type="number" id="capacity" name="capacity" min="0" required
                   value="<?= e($form['capacity']) ?>">
          </div>
        </div>
        <button type="submit" class="btn btn-gold"><?= $editing ? 'Update Venue' : 'Save Venue' ?></button>
        <?php if ($editing): ?><a class="btn btn-light" href="venues.php">Cancel</a><?php endif; ?>
      </form>
    </div>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>