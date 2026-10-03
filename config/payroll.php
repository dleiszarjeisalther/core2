<?php
/**
 * Payroll calculation helpers.
 * Philippine BIR withholding table: RR No. 11-2018 / TRAIN rates in effect
 * from January 1, 2023 onward. Holiday multipliers are configurable in the
 * HRIS workday calendar; this system defaults Special Working Day to 2.00x.
 */
function payroll_period_type(string $start, string $end): string
{
    return payroll_is_valid_cutoff($start, $end) ? 'semi-monthly' : 'custom';
}

function payroll_cutoff_dates(string $date): array
{
    $dt = new DateTime($date);
    $year = $dt->format('Y');
    $month = $dt->format('m');
    $day = (int)$dt->format('d');
    $lastDay = (int)$dt->format('t');

    if ($day <= 15) {
        return ["{$year}-{$month}-01", "{$year}-{$month}-15"];
    }
    return ["{$year}-{$month}-16", "{$year}-{$month}-{$lastDay}"];
}

function payroll_is_valid_cutoff(string $start, string $end): bool
{
    try {
        [$expectedStart, $expectedEnd] = payroll_cutoff_dates($start);
        return $start === $expectedStart && $end === $expectedEnd;
    } catch (Throwable $e) {
        return false;
    }
}

function payroll_cutoff_label(string $start, string $end): string
{
    return date('M d, Y', strtotime($start)) . ' – ' . date('M d, Y', strtotime($end));
}

function bir_withholding_tax(float $taxableIncome, string $periodType = 'monthly'): float
{
    $x = max(0.0, $taxableIncome);

    if ($periodType === 'semi-monthly') {
        if ($x <= 10417) return 0.0;
        if ($x <= 16666) return ($x - 10417) * 0.15;
        if ($x <= 33332) return 937.50 + (($x - 16667) * 0.20);
        if ($x <= 83332) return 4270.70 + (($x - 33333) * 0.25);
        if ($x <= 333332) return 16770.70 + (($x - 83333) * 0.30);
        return 91770.70 + (($x - 333333) * 0.35);
    }

    if ($x <= 20833) return 0.0;
    if ($x <= 33332) return ($x - 20833) * 0.15;
    if ($x <= 66666) return 1875.00 + (($x - 33333) * 0.20);
    if ($x <= 166666) return 8541.80 + (($x - 66667) * 0.25);
    if ($x <= 666666) return 33541.80 + (($x - 166667) * 0.30);
    return 183541.80 + (($x - 666667) * 0.35);
}

function payroll_statutory_deductions(float $basicSalary, float $grossPay, bool $semiMonthly): array
{
    $divisor = $semiMonthly ? 2 : 1;
    $msc = min(35000, max(5000, $basicSalary));
    $sss = round(($msc * 0.05) / $divisor, 2);
    $philHealthBase = min(100000, max(10000, $basicSalary));
    $philhealth = round(($philHealthBase * 0.05 * 0.50) / $divisor, 2);
    $pagibig = round((min(10000, max(0, $basicSalary)) * 0.02) / $divisor, 2);
    return compact('sss', 'philhealth', 'pagibig');
}
