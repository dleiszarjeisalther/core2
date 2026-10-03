<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../config/payroll.php';
requireRole('HR Administrator');

$pageTitle = 'Generate Payroll';
$errors = [];
$employees = $pdo->query("SELECT * FROM employees WHERE employment_status NOT IN ('Resigned','Terminated') ORDER BY first_name")->fetchAll();
$selectedEmployeeId = $_POST['employee_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $employeeId = $_POST['employee_id'] ?? '';
    $periodStart = $_POST['pay_period_start'] ?? '';
    $periodEnd = $_POST['pay_period_end'] ?? '';
    $bonusPercent = max(0, (float)($_POST['bonuses_percent'] ?? 10));

    if (!$employeeId) $errors[] = 'Please select an employee.';
    if (!$periodStart || !$periodEnd) $errors[] = 'Pay period dates are required.';
    if ($periodStart && $periodEnd && $periodStart > $periodEnd) $errors[] = 'Pay period start date cannot be later than the end date.';
    if ($periodStart && $periodEnd && !payroll_is_valid_cutoff($periodStart, $periodEnd)) {
        $errors[] = 'Payroll cutoff must be exactly 1–15 or 16–last day of the month (including the 31st when applicable).';
    }

    if (!$errors) {
        $empStmt = $pdo->prepare('SELECT * FROM employees WHERE employee_id = ?');
        $empStmt->execute([$employeeId]);
        $emp = $empStmt->fetch();
        if (!$emp) {
            $errors[] = 'Employee not found.';
        } else {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM payroll WHERE employee_id=? AND pay_period_start=? AND pay_period_end=?');
            $dup->execute([$employeeId, $periodStart, $periodEnd]);
            if ((int)$dup->fetchColumn() > 0) {
                $errors[] = 'Payroll has already been generated for this employee and cutoff.';
            }
        }
    }

    if (!$errors) {
        $monthlySalary = (float)$emp['basic_salary'];
        $cutoffSalary = round($monthlySalary / 2, 2);
        $dailyRate = $monthlySalary / 22;
        $hourlyRate = $dailyRate / 8;

        $attStmt = $pdo->prepare("SELECT a.*, COALESCE(wc.day_type,'Working Day') AS calendar_day_type,
                    COALESCE(wc.pay_multiplier,1.00) AS pay_multiplier
            FROM attendance a
            LEFT JOIN workday_calendar wc ON wc.calendar_date=a.attendance_date
            WHERE a.employee_id=? AND a.attendance_date BETWEEN ? AND ?
            ORDER BY a.attendance_date");
        $attStmt->execute([$employeeId, $periodStart, $periodEnd]);
        $attendance = $attStmt->fetchAll();

        $otMinutes = 0;
        $lateMinutes = 0;
        $absentDays = 0;
        $specialWorkingDays = 0;
        $specialWorkingPay = 0.0;

        foreach ($attendance as $a) {
            $otMinutes += (int)$a['overtime_minutes'];
            $lateMinutes += (int)$a['late_minutes'];
            if ($a['status'] === 'Absent') $absentDays++;

            if ($a['calendar_day_type'] === 'Special Working Day' && $a['time_in'] && $a['time_out']) {
                $specialWorkingDays++;
                $workedMinutes = max(0, (int)((strtotime($a['attendance_date'].' '.$a['time_out']) - strtotime($a['attendance_date'].' '.$a['time_in'])) / 60));
                if ($a['break_start'] && $a['break_end']) {
                    $workedMinutes -= max(0, (int)((strtotime($a['attendance_date'].' '.$a['break_end']) - strtotime($a['attendance_date'].' '.$a['break_start'])) / 60));
                }
                $workedFraction = min(1, max(0, $workedMinutes / 480));
                $premiumMultiplier = max(0, (float)$a['pay_multiplier'] - 1.0);
                $specialWorkingPay += $dailyRate * $premiumMultiplier * $workedFraction;
            }
        }

        $specialWorkingPay = round($specialWorkingPay, 2);
        $bonuses = round($cutoffSalary * ($bonusPercent / 100), 2);
        $overtimePay = round(($otMinutes / 60) * $hourlyRate * 1.25, 2);
        $lateDeduction = round(($lateMinutes / 60) * $hourlyRate, 2);
        $absenceDeduction = round($absentDays * $dailyRate, 2);

        // Semi-monthly payroll: base salary is one-half of the monthly salary.
        // Special Working Day adds the premium required to make that worked day 2×.
        $grossPay = round($cutoffSalary + $specialWorkingPay + $overtimePay + $bonuses, 2);

        $statutory = payroll_statutory_deductions($cutoffSalary, $grossPay, true);
        $sss = $statutory['sss'];
        $philhealth = $statutory['philhealth'];
        $pagibig = $statutory['pagibig'];
        $taxableIncome = max(0, $grossPay - $sss - $philhealth - $pagibig);
        $tax = round(bir_withholding_tax($taxableIncome, 'semi-monthly'), 2);

        $totalDeductions = round($sss + $philhealth + $pagibig + $tax + $lateDeduction + $absenceDeduction, 2);
        $netPay = round($grossPay - $totalDeductions, 2);

        $stmt = $pdo->prepare("INSERT INTO payroll (
            employee_id,pay_period_start,pay_period_end,basic_salary,overtime_pay,special_working_pay,
            allowances,incentives,bonuses,gross_pay,tax_deduction,sss_deduction,philhealth_deduction,
            pagibig_deduction,late_deduction,absence_deduction,total_deductions,net_pay,generated_by
        ) VALUES (?,?,?,?,?,?,0,0,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $employeeId,$periodStart,$periodEnd,$cutoffSalary,$overtimePay,$specialWorkingPay,
            $bonuses,$grossPay,$tax,$sss,$philhealth,$pagibig,$lateDeduction,$absenceDeduction,
            $totalDeductions,$netPay,$_SESSION['user_id']
        ]);

        $newId = $pdo->lastInsertId();
        audit($pdo,'PAYROLL_GENERATED','payroll',$newId,"Payroll generated for {$employeeId} {$periodStart} to {$periodEnd}; special working days={$specialWorkingDays}");
        flash('success', 'Payroll generated successfully.');
        redirect('/modules/payroll/payslip.php?id=' . $newId);
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="card" style="max-width:720px;">
    <div class="card-header"><h2>Generate Payroll</h2></div>
    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $e): ?><?= htmlspecialchars($e) ?><br><?php endforeach; ?></div><?php endif; ?>
    <div class="alert alert-info">
        <strong>Payroll Cutoff:</strong> 1–15 and 16–last day of the month. A month with 31 days uses 16–31 for the second cutoff.<br>
        <strong>Special Working Day:</strong> the Workday & Holiday Calendar marks the date, and an employee who actually works it receives the configured 2.00× daily-rate rule. The payroll record stores the additional special-day premium separately.
    </div>
    <form method="POST">
        <?= csrf_field() ?>
        <div class="form-group"><label>Employee *</label><select name="employee_id" required><option value="">-- Select Employee --</option><?php foreach ($employees as $e): ?><option value="<?= htmlspecialchars($e['employee_id']) ?>" <?= $selectedEmployeeId == $e['employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['first_name'].' '.$e['last_name'].' ('.$e['employee_id'].') — ₱'.number_format($e['basic_salary'],2).' monthly') ?></option><?php endforeach; ?></select></div>
        <div class="form-grid">
            <div class="form-group"><label>Pay Period Start *</label><input type="date" name="pay_period_start" value="<?= htmlspecialchars($_POST['pay_period_start'] ?? '') ?>" required></div>
            <div class="form-group"><label>Pay Period End *</label><input type="date" name="pay_period_end" value="<?= htmlspecialchars($_POST['pay_period_end'] ?? '') ?>" required></div>
        </div>
        <div class="form-group"><label>Bonuses (%) — percentage of this cutoff's salary</label><input type="number" step="0.01" min="0" name="bonuses_percent" value="<?= htmlspecialchars($_POST['bonuses_percent'] ?? '10') ?>"></div>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Generate Payroll</button><a href="<?= siteUrl('modules/payroll/list.php') ?>" class="btn btn-secondary">Cancel</a></div>
    </form>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
