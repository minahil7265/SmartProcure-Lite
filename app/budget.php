<?php
// /var/www/smartprocure/app/budget.php
//
// Departmental budget rules (section 8 of the proposal):
//   available = allocated amount - committed amount
// for the department and the period that contains the request date.

/**
 * The budget period that contains a date, e.g. '2026' (format comes from config).
 */
function budgetPeriodFor(string $date): string
{
    return date((string) config('budget_period_format'), strtotime($date));
}

/**
 * Find a department's budget for one period.
 * Pass $forUpdate = true inside a transaction to lock the row.
 */
function findBudget(int $departmentId, string $period, bool $forUpdate = false): ?array
{
    global $pdo;

    $sql = 'SELECT id, department_id, budget_period, allocated_amount, committed_amount
            FROM budgets
            WHERE department_id = :dept AND budget_period = :period
            LIMIT 1';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['dept' => $departmentId, 'period' => $period]);
    $budget = $stmt->fetch();

    return $budget ?: null;
}

/**
 * Money still available in a budget row.
 */
function availableBudget(array $budget): float
{
    return round((float) $budget['allocated_amount'] - (float) $budget['committed_amount'], 2);
}

/**
 * Change the committed amount (positive to commit, negative to release).
 * Call inside a transaction after locking the row with findBudget(..., true).
 */
function changeCommittedAmount(int $budgetId, float $change): void
{
    global $pdo;

    $stmt = $pdo->prepare(
        'UPDATE budgets
         SET committed_amount = GREATEST(committed_amount + :change, 0)
         WHERE id = :id'
    );
    $stmt->execute(['change' => $change, 'id' => $budgetId]);
}
