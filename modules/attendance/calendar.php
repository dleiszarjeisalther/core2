<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/security.php';
requireRole('HR Administrator');

$pageTitle = 'Workday & Holiday Calendar';
$errors = [];
$selectedDate = $_POST['calendar_date'] ?? $_GET['date'] ?? date('Y-m-d');
$selectedType = $_POST['day_type'] ?? 'Working Day';
$eventName = trim($_POST['event_name'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$allowedTypes = [
    'Working Day' => 1.00,
    'Special Working Day' => 2.00,
    'Special Non-Working Day' => 0.00,
    'Non-Working Day' => 0.00,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'save';
    $selectedDate = $_POST['calendar_date'] ?? '';

    if ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM workday_calendar WHERE calendar_date = ?');
        $stmt->execute([$selectedDate]);
        audit($pdo, 'WORKDAY_CALENDAR_DELETED', 'workday_calendar', $selectedDate, 'Calendar entry deleted');
        flash('success', 'Calendar entry deleted. The date is treated as a normal Working Day again.');
        redirect('/modules/attendance/calendar.php?month=' . urlencode(substr($selectedDate, 0, 7)));
    }

    $selectedType = $_POST['day_type'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) $errors[] = 'A valid date is required.';
    if (!array_key_exists($selectedType, $allowedTypes)) $errors[] = 'Please select a valid day type.';
    if ($selectedType !== 'Working Day' && $eventName === '') $errors[] = 'Holiday / event name is required for non-working or special dates.';

    if (!$errors) {
        $stmt = $pdo->prepare("INSERT INTO workday_calendar (calendar_date, day_type, event_name, notes, pay_multiplier, created_by)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE day_type=VALUES(day_type), event_name=VALUES(event_name), notes=VALUES(notes), pay_multiplier=VALUES(pay_multiplier), created_by=VALUES(created_by)");
        $stmt->execute([$selectedDate, $selectedType, $eventName ?: null, $notes ?: null, $allowedTypes[$selectedType], $_SESSION['user_id']]);
        audit($pdo, 'WORKDAY_CALENDAR_SAVED', 'workday_calendar', $selectedDate, "{$selectedType} calendar entry saved");
        flash('success', 'Calendar data saved successfully.');
        redirect('/modules/attendance/calendar.php?month=' . urlencode(substr($selectedDate, 0, 7)));
    }
}

$month = $_GET['month'] ?? substr($selectedDate, 0, 7);
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$stmt = $pdo->prepare('SELECT * FROM workday_calendar WHERE calendar_date BETWEEN ? AND ? ORDER BY calendar_date');
$stmt->execute([$monthStart, $monthEnd]);
$calendarRows = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <div>
            <h2>Workday & Holiday Calendar</h2>
            <p class="text-muted">Set a date as Working Day, Special Working Day, Special Non-Working Day, or Non-Working Day. Special Working Day is configured at <strong>2.00×</strong> pay when the employee actually works.</p>
        </div>
        <a href="<?= siteUrl('modules/attendance/report.php') ?>" class="btn btn-secondary">Attendance Breakdown</a>
    </div>

    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?></div><?php endif; ?>

    <form method="POST" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div class="form-group">
            <label>Date *</label>
            <input type="date" name="calendar_date" value="<?= htmlspecialchars($selectedDate) ?>" required>
        </div>
        <div class="form-group">
            <label>Day Type *</label>
            <select name="day_type" required onchange="updateCalendarHint(this.value)">
                <?php foreach ($allowedTypes as $type => $multiplier): ?>
                    <option value="<?= htmlspecialchars($type) ?>" <?= $selectedType === $type ? 'selected' : '' ?>><?= htmlspecialchars($type) ?><?= $type === 'Special Working Day' ? ' — 2× Pay' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Holiday / Event Name</label>
            <input type="text" name="event_name" value="<?= htmlspecialchars($eventName) ?>" placeholder="e.g. Company Special Working Day">
        </div>
        <div class="form-group">
            <label>Notes</label>
            <input type="text" name="notes" value="<?= htmlspecialchars($notes) ?>" placeholder="Optional notes">
        </div>
        <div style="grid-column:1/-1" class="alert alert-info" id="calendarHint">Regular dates with no entry are automatically treated as Working Day.</div>
        <div style="grid-column:1/-1"><button class="btn btn-primary">Save Calendar Data</button></div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <div><h2>Calendar Data — <?= htmlspecialchars(date('F Y', strtotime($monthStart))) ?></h2><p class="text-muted">Only dates entered here override the default Working Day behavior.</p></div>
        <form method="get" style="display:flex;gap:8px;align-items:center"><input type="month" name="month" value="<?= htmlspecialchars($month) ?>"><button class="btn btn-secondary">View</button></form>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Day Type</th><th>Holiday / Event</th><th>Pay Multiplier</th><th>Notes</th><th>Action</th></tr></thead>
            <tbody>
            <?php if (!$calendarRows): ?>
                <tr><td colspan="6" class="empty-state">No calendar data for this month. Regular dates are treated as Working Day.</td></tr>
            <?php endif; ?>
            <?php foreach ($calendarRows as $row): ?>
                <tr>
                    <td><?= htmlspecialchars(date('M d, Y', strtotime($row['calendar_date']))) ?></td>
                    <td><?= htmlspecialchars($row['day_type']) ?></td>
                    <td><?= htmlspecialchars($row['event_name'] ?? '—') ?></td>
                    <td><strong><?= number_format((float)$row['pay_multiplier'], 2) ?>×</strong></td>
                    <td><?= htmlspecialchars($row['notes'] ?? '—') ?></td>
                    <td>
                        <a class="btn btn-secondary btn-sm" href="<?= siteUrl('modules/attendance/calendar.php?date=' . urlencode($row['calendar_date']) . '&month=' . urlencode($month)) ?>">Edit</a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this calendar entry?');">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="calendar_date" value="<?= htmlspecialchars($row['calendar_date']) ?>">
                            <button class="btn btn-danger btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
function updateCalendarHint(type) {
    const hint = document.getElementById('calendarHint');
    const messages = {
        'Working Day': 'Regular dates are paid normally. You may leave the event name blank.',
        'Special Working Day': 'Employees who actually work this date receive the configured 2.00× daily-rate rule. Attendance must have Time In and Time Out.',
        'Special Non-Working Day': 'This date is marked as a special non-working date. It is not treated as a normal working day.',
        'Non-Working Day': 'This date is marked as non-working and is excluded from normal working-day expectations.'
    };
    hint.textContent = messages[type] || '';
}
updateCalendarHint(<?= json_encode($selectedType) ?>);
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
