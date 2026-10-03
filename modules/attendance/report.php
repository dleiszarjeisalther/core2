<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/payroll.php';
requireLogin();
if (!isAdmin()) { redirect('/modules/attendance/list.php'); }

$pageTitle = 'Attendance Hours Report';
$period = $_GET['period'] ?? 'first';
$end = $_GET['end'] ?? date('Y-m-d');
$start = $_GET['start'] ?? date('Y-m-d', strtotime($end . ' -14 days'));

if ($period === 'first') {
    $yearMonth = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) $yearMonth = date('Y-m');
    $start = $yearMonth . '-01';
    $end = $yearMonth . '-15';
} elseif ($period === 'second') {
    $yearMonth = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) $yearMonth = date('Y-m');
    $start = $yearMonth . '-16';
    $end = date('Y-m-t', strtotime($start));
} elseif ($period !== 'custom') {
    $period = 'first';
}

$sql = "SELECT e.employee_id,e.first_name,e.last_name,
COALESCE(SUM(CASE WHEN a.time_in IS NOT NULL AND a.time_out IS NOT NULL THEN 1 ELSE 0 END),0) work_days,
COUNT(a.attendance_id) records,
COALESCE(SUM(CASE WHEN a.time_in IS NOT NULL AND a.time_out IS NOT NULL THEN GREATEST(0,TIMESTAMPDIFF(MINUTE,a.time_in,a.time_out)-IF(a.break_start IS NOT NULL AND a.break_end IS NOT NULL,TIMESTAMPDIFF(MINUTE,a.break_start,a.break_end),0)) ELSE 0 END),0) total_minutes,
COALESCE(SUM(CASE WHEN a.break_start IS NOT NULL AND a.break_end IS NOT NULL THEN GREATEST(0,TIMESTAMPDIFF(MINUTE,a.break_start,a.break_end)) ELSE 0 END),0) break_minutes,
COALESCE(SUM(a.overtime_minutes),0) overtime_minutes,
COALESCE(SUM(a.late_minutes),0) late_minutes,
COALESCE(SUM(CASE WHEN wc.day_type='Special Working Day' AND a.time_in IS NOT NULL AND a.time_out IS NOT NULL THEN 1 ELSE 0 END),0) special_working_days
FROM employees e
LEFT JOIN attendance a ON e.employee_id=a.employee_id AND a.attendance_date BETWEEN ? AND ?
LEFT JOIN workday_calendar wc ON wc.calendar_date=a.attendance_date
WHERE e.employment_status NOT IN ('Resigned','Terminated')
GROUP BY e.employee_id,e.first_name,e.last_name
ORDER BY e.first_name,e.last_name";
$stmt=$pdo->prepare($sql); $stmt->execute([$start,$end]); $report=$stmt->fetchAll();
include __DIR__.'/../../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <div><h2>Attendance Breakdown — <?= $period==='first'?'1–15':($period==='second'?'16–End of Month':'Custom Period') ?></h2><p class="text-muted">Shows how many days employees worked and the hour breakdown. Break time is deducted from worked hours.</p></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><a href="<?= siteUrl('modules/attendance/list.php') ?>" class="btn btn-secondary">Daily Attendance</a><a href="<?= siteUrl('modules/attendance/calendar.php') ?>" class="btn btn-secondary">Workday & Holiday Calendar</a></div>
    </div>
    <form method="get" class="form-grid">
        <div class="form-group"><label>Payroll Cutoff</label><select name="period" onchange="this.form.submit()"><option value="first" <?= $period==='first'?'selected':'' ?>>1–15</option><option value="second" <?= $period==='second'?'selected':'' ?>>16–End of Month (30/31 or 28/29)</option><option value="custom" <?= $period==='custom'?'selected':'' ?>>Custom</option></select></div>
        <?php if ($period !== 'custom'): ?><div class="form-group"><label>Month</label><input type="month" name="month" value="<?= htmlspecialchars($yearMonth ?? date('Y-m')) ?>"></div><?php endif; ?>
        <div class="form-group"><label>Start Date</label><input type="date" name="start" value="<?= htmlspecialchars($start) ?>" <?= $period!=='custom'?'readonly':'' ?>></div>
        <div class="form-group"><label>End Date</label><input type="date" name="end" value="<?= htmlspecialchars($end) ?>" <?= $period!=='custom'?'readonly':'' ?>></div>
        <div class="form-actions" style="align-self:end"><button class="btn btn-primary">Filter</button><button type="button" onclick="window.print()" class="btn btn-secondary">Print Report</button></div>
    </form>
    <div class="table-wrap"><table><thead><tr><th>Employee</th><th>Work Days</th><th>Records</th><th>Total Hours</th><th>Break Hours</th><th>Regular Hours</th><th>OT Hours</th><th>Late</th><th>Special Working Days</th></tr></thead><tbody>
    <?php foreach($report as $r):
        $total=(int)$r['total_minutes']; $break=(int)$r['break_minutes']; $ot=(int)$r['overtime_minutes']; $regular=max(0,$total-$ot);
    ?>
    <tr>
        <td><?= htmlspecialchars($r['first_name'].' '.$r['last_name'].' ('.$r['employee_id'].')') ?></td>
        <td><strong><?= (int)$r['work_days'] ?></strong></td>
        <td><?= (int)$r['records'] ?></td>
        <td><strong><?= intdiv($total,60) ?>h <?= $total%60 ?>m</strong></td>
        <td><?= intdiv($break,60) ?>h <?= $break%60 ?>m</td>
        <td><?= intdiv($regular,60) ?>h <?= $regular%60 ?>m</td>
        <td><?= number_format($ot/60,2) ?>h</td>
        <td><?= (int)$r['late_minutes'] ?>m</td>
        <td><?= (int)$r['special_working_days'] ?></td>
    </tr>
    <?php endforeach; if(!$report): ?><tr><td colspan="9" class="empty-state">No attendance records for this period.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php include __DIR__.'/../../includes/footer.php'; ?>
