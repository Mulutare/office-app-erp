<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Services\TenantContext;

/** Company HR registers only. Self/team routes retain their narrower domain queries. */
final class HrListService
{
    public const EMPLOYEE_SORTS = ['name' => 'full_name', 'number' => 'employee_number',
        'department' => 'department_name', 'status' => 'employment_status', 'hire_date' => 'hire_date'];
    public const ATTENDANCE_SORTS = ['name' => 'full_name', 'number' => 'employee_number',
        'department' => 'department_name', 'status' => 'attendance_status', 'date' => 'attendance_date'];

    public function employees(array $input): SqlList
    {
        foreach (['department', 'branch'] as $key) {
            if (ListQuery::text($input[$key] ?? '') === '0') $input[$key] = '';
        }
        $query = new ListQuery($input, self::EMPLOYEE_SORTS, 'name', ['status', 'department', 'branch']);
        $sql = "SELECT e.employee_id,e.employee_number,e.first_name,e.middle_name,e.last_name,e.preferred_name,
            CONCAT_WS(' ',e.first_name,NULLIF(e.middle_name,''),e.last_name) full_name,
            e.work_email,e.work_phone,e.job_title,e.employment_type,e.employment_status,e.hire_date,e.user_id,
            d.department_id,d.code department_code,d.name department_name,
            u.username,u.active account_active,b.branch_id,b.code branch_code,b.name branch_name
            FROM hr_employees e
            LEFT JOIN hr_departments d ON d.company_id=e.company_id AND d.department_id=e.department_id
            LEFT JOIN users u ON u.user_id=e.user_id AND u.deleted_at IS NULL
            " . $this->branchJoins() . "
            WHERE e.company_id=:company AND e.deleted_at IS NULL";
        return new SqlList(\db(), $sql, ['company' => (new TenantContext())->companyId()], $query,
            ['employee_number', 'full_name', "CONCAT_WS(' ',first_name,last_name)", 'preferred_name',
                'work_email', 'work_phone', 'username', 'department_name', 'department_code',
                'branch_name', 'branch_code', 'job_title', 'employment_status'],
            self::EMPLOYEE_SORTS, 'employee_id', ['status' => 'employment_status', 'department' => 'department_id', 'branch' => 'branch_id']);
    }

    public function attendance(array $input): SqlList
    {
        foreach (['department', 'branch'] as $key) {
            if (ListQuery::text($input[$key] ?? '') === '0') $input[$key] = '';
        }
        $period = self::attendancePeriod($input);
        $input['date'] = $period['date'];
        $input['period'] = $period['period'];
        $query = new ListQuery($input, self::ATTENDANCE_SORTS, 'name', ['status', 'department', 'branch', 'date', 'period']);
        // Daily shows the roster, including employees without an entry. Longer
        // periods show recorded employee-days, without inventing absent days.
        $attendanceJoin = $period['period'] === 'daily' ? 'LEFT JOIN' : 'INNER JOIN';
        $sql = "SELECT e.employee_id,e.employee_number,e.first_name,e.last_name,e.preferred_name,e.job_title,
            CONCAT_WS(' ',e.first_name,NULLIF(e.middle_name,''),e.last_name) full_name,
            e.department_id,d.name department_name,b.branch_id,b.code branch_code,b.name branch_name,
            a.attendance_id,COALESCE(a.attendance_date,:display_date) attendance_date,
            COALESCE(a.attendance_status,'not_recorded') attendance_status,a.check_in_at,a.check_out_at,
            a.work_minutes,a.gross_minutes,a.break_minutes,a.target_work_minutes,a.work_variance_minutes,
            a.source,a.notes,a.updated_at
            FROM hr_employees e
            LEFT JOIN hr_departments d ON d.company_id=e.company_id AND d.department_id=e.department_id AND d.deleted_at IS NULL
            " . $this->branchJoins() . "
            $attendanceJoin attendance_records a ON a.company_id=e.company_id AND a.employee_id=e.employee_id
                AND a.attendance_date>=:period_start AND a.attendance_date<=:period_end
            WHERE e.company_id=:company AND e.deleted_at IS NULL AND e.employment_status IN ('active','on_leave')";
        return new SqlList(\db(), $sql, ['company' => (new TenantContext())->companyId(),
            'period_start' => $period['start'], 'period_end' => $period['end'], 'display_date' => $input['date']], $query,
            ['employee_number', 'full_name', 'department_name', 'branch_name', 'branch_code', 'attendance_status', 'attendance_date'],
            self::ATTENDANCE_SORTS, 'employee_id ASC, attendance_date ASC, attendance_id', ['status' => 'attendance_status', 'department' => 'department_id', 'branch' => 'branch_id']);
    }

    /** One calendar definition shared by the register, summaries and exports. */
    public static function attendancePeriod(array $input): array
    {
        $date = self::date($input['date'] ?? '', date('Y-m-d'));
        $period = ListQuery::text($input['period'] ?? 'daily');
        if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) $period = 'daily';
        $anchor = new \DateTimeImmutable($date);
        $start = match ($period) {
            'weekly' => $anchor->modify('-' . ((int)$anchor->format('N') - 1) . ' days'),
            'monthly' => $anchor->modify('first day of this month'),
            default => $anchor,
        };
        $end = match ($period) {
            'weekly' => $start->modify('+6 days'),
            'monthly' => $anchor->modify('last day of this month'),
            default => $anchor,
        };
        return ['period'=>$period, 'date'=>$date, 'start'=>$start->format('Y-m-d'), 'end'=>$end->format('Y-m-d')];
    }

    public function options(): array
    {
        $company = (new TenantContext())->companyId();
        $options = [];
        foreach (['department' => ['hr_departments', 'department_id'], 'branch' => ['organization_branches', 'branch_id']] as $key => [$table, $id]) {
            $statement = \db()->prepare("SELECT $id id,code,name FROM $table WHERE company_id=? AND deleted_at IS NULL ORDER BY name,$id");
            $statement->execute([$company]);
            $options[$key] = array_column($statement->fetchAll(\PDO::FETCH_ASSOC), 'name', 'id');
        }
        return $options;
    }

    public function employeeOptions(): array
    {
        $statement = \db()->prepare("SELECT employee_id,employee_number,CONCAT_WS(' ',first_name,last_name) employeeName
            FROM hr_employees WHERE company_id=? AND deleted_at IS NULL AND employment_status IN ('active','on_leave')
            ORDER BY last_name,first_name,employee_id");
        $statement->execute([(new TenantContext())->companyId()]);
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function branchJoins(): string
    {
        return "LEFT JOIN hr_employee_position_assignments ep ON ep.company_id=e.company_id AND ep.employee_id=e.employee_id
                AND ep.assignment_status='current' AND ep.current_marker=1 AND ep.effective_to IS NULL
            LEFT JOIN organization_positions p ON p.company_id=ep.company_id AND p.position_id=ep.position_id AND p.deleted_at IS NULL
            LEFT JOIN organization_branches b ON b.company_id=p.company_id AND b.branch_id=p.branch_id AND b.deleted_at IS NULL";
    }

    public static function date(mixed $input, string $fallback): string
    {
        $text = ListQuery::text($input);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        return $date && $date->format('Y-m-d') === $text ? $text : $fallback;
    }
}
