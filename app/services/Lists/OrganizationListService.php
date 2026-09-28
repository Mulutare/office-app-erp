<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Services\TenantContext;
use InvalidArgumentException;

/** Read models only. Organization and HR controllers retain their permission gates. */
final class OrganizationListService
{
    public function listing(string $entity, array $input): array
    {
        [$sql, $key, $id, $search, $sorts, $filters, $summary] = $this->definition($entity);
        $query = new ListQuery($input, $sorts, 'name', array_keys($filters));
        $params = ['company_id' => (new TenantContext())->companyId()];
        $list = new SqlList(\db(), $sql, $params, $query, $search, $sorts, $id, $filters);
        $page = $list->page();
        // Catalogue cards describe the whole company, independently of the page/search.
        $all = new SqlList(\db(), $sql, $params, new ListQuery([], $sorts, 'name'), [], $sorts, $id);
        return [$key => $page['rows'], 'summary' => $all->aggregate($summary), 'list' => $page, 'exportList'=>$list,
            'sorts' => array_combine(array_keys($sorts), array_map(
                static fn (string $key): string => ucwords(str_replace('_', ' ', $key)), array_keys($sorts)
            ))];
    }

    public function columns(string $entity): array
    {
        $identity=['code'=>'Code','name'=>'Name'];
        return $identity + match($entity) {
            'branches'=>['city'=>'City','country_code'=>'Country','contact_email'=>'Email','contact_phone'=>'Phone','is_head_office'=>'Head office','active'=>'Active'],
            'departments'=>['parent_department_name'=>'Parent department','description'=>'Description','employee_count'=>'Employees','current_employee_count'=>'Current employees','active'=>'Active'],
            'job-titles'=>['job_family'=>'Job family','grade_level'=>'Grade','description'=>'Description','active'=>'Active'],
            'positions'=>['branch_name'=>'Branch','department_name'=>'Department','job_title_name'=>'Job title','status'=>'Status','approved_headcount'=>'Approved headcount'],
            'leave-policies'=>['annual_entitlement'=>'Annual entitlement','approvalWorkflowLabel'=>'Approval route','hr_approver_name'=>'HR approver','request_count'=>'Requests','active'=>'Active'],
            default=>throw new InvalidArgumentException('Unknown organization list.'),
        };
    }

    private function definition(string $entity): array
    {
        $sorts = ['name' => 'name', 'code' => 'code', 'status' => 'active', 'updated' => 'updated_at'];
        $filters = ['active' => 'active'];
        $summary = ['total' => 'COUNT(*)', 'active' => 'COALESCE(SUM(active = 1),0)'];
        return match ($entity) {
            'branches' => [
                'SELECT b.* FROM organization_branches b WHERE b.company_id=:company_id AND b.deleted_at IS NULL',
                'branches', 'branch_id', ['code','name','contact_email','contact_phone','city','country_code'],
                $sorts, $filters, $summary + ['headOffices' => 'COALESCE(SUM(is_head_office = 1),0)'],
            ],
            'job-titles' => [
                'SELECT j.* FROM organization_job_titles j WHERE j.company_id=:company_id AND j.deleted_at IS NULL',
                'jobTitles', 'job_title_id', ['code','name','job_family','grade_level','description'],
                $sorts + ['family' => 'job_family'], $filters,
                $summary + ['families' => "COUNT(DISTINCT NULLIF(LOWER(TRIM(job_family)),''))"],
            ],
            'positions' => [
                'SELECT p.*, b.name branch_name, d.name department_name, j.name job_title_name, j.grade_level
                 FROM organization_positions p
                 LEFT JOIN organization_branches b ON b.company_id=p.company_id AND b.branch_id=p.branch_id AND b.deleted_at IS NULL
                 INNER JOIN hr_departments d ON d.company_id=p.company_id AND d.department_id=p.department_id AND d.deleted_at IS NULL
                 INNER JOIN organization_job_titles j ON j.company_id=p.company_id AND j.job_title_id=p.job_title_id AND j.deleted_at IS NULL
                 WHERE p.company_id=:company_id AND p.deleted_at IS NULL',
                'positions', 'position_id', ['code','name','branch_name','department_name','job_title_name','status'],
                ['name'=>'name','code'=>'code','status'=>'status','department'=>'department_name','branch'=>'branch_name'],
                ['status'=>'status'], ['total'=>'COUNT(*)','open'=>"COALESCE(SUM(status='open'),0)",
                    'planned'=>"COALESCE(SUM(status='planned'),0)",'approvedHeadcount'=>'COALESCE(SUM(approved_headcount),0)'],
            ],
            'departments' => [
                "SELECT d.*, parent.name parent_department_name,
                    (SELECT COUNT(*) FROM hr_employees e WHERE e.company_id=d.company_id AND e.department_id=d.department_id AND e.deleted_at IS NULL) employee_count,
                    (SELECT COUNT(*) FROM hr_employees e WHERE e.company_id=d.company_id AND e.department_id=d.department_id AND e.deleted_at IS NULL AND e.employment_status<>'terminated') current_employee_count
                 FROM hr_departments d LEFT JOIN hr_departments parent ON parent.company_id=d.company_id
                    AND parent.department_id=d.parent_department_id AND parent.deleted_at IS NULL
                 WHERE d.company_id=:company_id AND d.deleted_at IS NULL",
                'departments', 'department_id', ['code','name','parent_department_name','description'], $sorts, $filters,
                $summary + ['topLevel'=>'COALESCE(SUM(parent_department_id IS NULL),0)','currentEmployees'=>'COALESCE(SUM(current_employee_count),0)'],
            ],
            'leave-policies' => [
                "SELECT t.*, u.display_name hr_approver_name,
                    CASE t.approval_workflow WHEN 'none' THEN 'No approval' WHEN 'hr' THEN 'HR only'
                        WHEN 'manager_then_hr' THEN 'Manager, then HR' ELSE 'Manager only' END approvalWorkflowLabel,
                    (SELECT COUNT(*) FROM hr_leave_requests r WHERE r.company_id=t.company_id AND r.leave_type_id=t.leave_type_id) request_count,
                    (SELECT COUNT(*) FROM hr_leave_requests r WHERE r.company_id=t.company_id AND r.leave_type_id=t.leave_type_id AND r.request_status='pending') pending_request_count
                 FROM hr_leave_types t LEFT JOIN users u ON u.user_id=t.hr_approver_user_id
                 WHERE t.company_id=:company_id AND t.deleted_at IS NULL",
                'policies', 'leave_type_id', ['code','name','approval_workflow','hr_approver_name'], $sorts, $filters,
                $summary + ['approvalRequired'=>'COALESCE(SUM(requires_approval=1),0)','requests'=>'COALESCE(SUM(request_count),0)'],
            ],
            default => throw new InvalidArgumentException('Unknown organization list.'),
        };
    }
}
