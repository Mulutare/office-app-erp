# OfficeApp ERP — implementation and compatibility report

Date: 2026-09-28. Current uncommitted tree at HEAD `6787559`. No reset, checkout/restore, branch change, staging, commit, push or deployment.

The applicable smart-list implementation and discovery pass are complete in the working tree. Final user steering was to finish with basic compatibility checks, not keep repeating broad suites. The last compatibility fix resolves Finance incident `ERR-20260928-9E4452EA7825`: the Finance navigation removed `legacy-expenses` for users with the modern Expenses grant, then dereferenced it in both navigation tiers. Both loops now skip absent entries. The real Finance controller/layout renders successfully with PHP warnings treated as failures.

The audit has 120 register/query definitions across HR/Organization, Attendance, Sales, Inventory, Procurement, Finance, Assets and Administration. It includes every discovered navigation surface, runtime GET registration, related history and the concrete N/A classifications. Dashboard, Analytics, configuration, API and atomic-document screens are explicitly classified. See `OfficeApp-list-audit.md` (repository copy `docs/SMART_LIST_AUDIT.md`).

## Preserved work and completed gaps

The latest handoff already had the main HR/Attendance/Sales/Inventory/Procurement work and these Finance registers wired: Receivables, Invoices, Receipts, Journals, Expenses, shared Settlements, Chart of Accounts, General Ledger, AR Aging, AP Aging/Payables, Cash & Bank, Staff Loans, Bank/GL mappings and Bank statement register. Their existing work was preserved; verification or demonstrated defects justified the follow-up fixes. There was no saved Git snapshot at that handoff, so the report does not falsely assign every uncommitted line to this continuation.

The completed gaps include organization masters; leave/balance/calendar/self/team support registers; Sales pricing/history/variants and secondary serial/commission/target exports; Quick Sale sections and history; DSA/DSP reports/incentives/settlements; Inventory stock-request support/history and daily stock history; Procurement supporting documents; Finance periods/year/history, expenses support, loan detail, statements, bank detail and invoice payments; Assets; Administration; and authorized document child registers. The audit matrix lists each exact register, route, controller, service, columns, options, scope and tests.

Attendance supports daily, weekly (Monday–Sunday) and monthly selection and full filtered CSV/XLSX exports. Daily includes the roster's not-recorded state; longer periods contain recorded employee-days. Leave headings now retain readable horizontal layout; shared controls wrap for narrow screens. Team editing retains all selected members independently of the visible member page.

## Imports and business behavior

Enabled imports: Employees and Attendance (create only), Suppliers (create only), Customers/Products (explicit create or update), Quotations (supported drafts only; explicit create or update). All rows are validated, duplicate/external IDs are company scoped, referential choices are authorized, confirmations bind actor/company/file/mapping/mode, and execution is transactional with rollback/savepoints. Workflow posting/approval remains in its domain service. Posted accounting, stock history, payments and completed settlements have no bulk import.

Only migration 099 adds the sales-manager incentive-settlement grant by code and propagates it to company templates. 088/097/098 are unchanged. Migration testing was confined to the disposable database. DSA policy/normalization and production data were not changed.

## Validation

Focused suites: **1146 checks passed in 14 suites**; 4 unauthorized HR/Attendance import/export requests returned 403. Basic compatibility: **25 passed** — 18 full controller/layout pages across modules and 7 Finance navigation states, isolated requests with warnings converted to exceptions. Populated fixtures exceed 25 records (typically 105; larger history/master fixtures where former caps required it), including search beyond page one, 25/50/100 sizes, count, empty results, status/options, namespaces, tenant/hierarchy/warehouse scope and all-result CSV/XLSX.

| Focused suite | Passed | Failed |
|---|---|---|
| smart-lists.php | 39 | 0 |
| smart-list-attendance-periods.php | 24 | 0 |
| smart-list-filter-options.php | 138 | 0 |
| smart-list-supporting.php | 101 | 0 |
| smart-list-sales.php | 76 | 0 |
| smart-list-inventory.php | 110 | 0 |
| smart-list-procurement.php | 33 | 0 |
| smart-list-finance.php | 248 | 0 |
| smart-list-assets.php | 37 | 0 |
| smart-list-administration.php | 54 | 0 |
| smart-list-documents.php | 216 | 0 |
| smart-list-imports.php | 28 | 0 |
| data-exchange.php | 18 | 0 |
| data-exchange-integration.php | 24 | 0 |

Broader checks were run once, with failed suites compared against HEAD 6787559 on separately cloned, identical starting fixtures. They are **not all green**.

| Broader suite | Latest result |
|---|---|
| effective-permission-policy.php | PASS (12 checks) |
| company-update-permission-preservation.php | PASS (6 checks) |
| company-user-role-assignment.php | PASS (27 checks) |
| migration-checksum-compatibility.php | PASS (6 checks) |
| sales-fulfilment-contract.php | PASS (12 checks) |
| assets-domain.php | PASS (5 checks) |
| assets-route-contract.php | PASS (14 checks) |
| inventory-module-contract.php | FAIL — 1 failed; [PASS] Reservation retry cannot downgrade a completed delivery to ready / Inventory module contract: 113 check(s), 1 failure(s). |
| sales-module-contract.php | PASS (1 checks) |
| controlled-internal-transfer-contract.php | FAIL — 3 failed; PASS Sales does not create automatic replenishment transfers / 14 controlled transfer contract checks, 3 failures |
| module-role-entitlements.php | PASS (15 checks) |
| attendance-geofence-authorization.php | FAIL — 6 failed;  / 18 authorization/geofence checks, 6 failures |
| permission-template-upgrade.php | FAIL — 2 failed;  / 3 permission-upgrade checks, 2 failures |
| module-authorization-contract.php | FAIL — 2 failed; PASS Declared dependency is Assets to Finance, not Sales to Inventory / 23 module authorization checks, 2 failures |
| procurement-receiving-rbac-contract.php | FAIL — 1 failed; 13 Procurement/RBAC contract checks, 1 failures / FAIL Inbound destination selection requires operational and Procurement permission |
| sales-hierarchy-scope.php | FAIL — 3 failed; PASS Inactive parent rejected / 11 passed, 3 failed |
| run.php | FAIL — 12 failed;  / 246 checks, 12 failures |

### Baseline comparison and blockers

| Suite | Identical-fixture current / HEAD evidence |
|---|---|
| inventory-module-contract.php | [PASS] Reservation retry cannot downgrade a completed delivery to ready / Inventory module contract: 113 check(s), 2 failure(s).; HEAD: [PASS] Reservation retry cannot downgrade a completed delivery to ready / Inventory module contract: 113 check(s), 1 failure(s). |
| controlled-internal-transfer-contract.php | PASS Sales does not create automatic replenishment transfers / 14 controlled transfer contract checks, 3 failures; HEAD: PASS Sales does not create automatic replenishment transfers / 14 controlled transfer contract checks, 3 failures |
| attendance-geofence-authorization.php |  / 18 authorization/geofence checks, 4 failures; HEAD:  / 18 authorization/geofence checks, 4 failures |
| permission-template-upgrade.php |  / 3 permission-upgrade checks, 2 failures; HEAD:  / 3 permission-upgrade checks, 2 failures |
| module-authorization-contract.php | PASS Declared dependency is Assets to Finance, not Sales to Inventory / 23 module authorization checks, 2 failures; HEAD: PASS Declared dependency is Assets to Finance, not Sales to Inventory / 23 module authorization checks, 2 failures |
| procurement-receiving-rbac-contract.php | 13 Procurement/RBAC contract checks, 1 failures / FAIL Inbound destination selection requires operational and Procurement permission; HEAD: 13 Procurement/RBAC contract checks, 1 failures / FAIL Inbound destination selection requires operational and Procurement permission |
| sales-hierarchy-scope.php | PASS Inactive parent rejected / 11 passed, 3 failed; HEAD: PASS Inactive parent rejected / 11 passed, 3 failed |
| run.php |  / 91 checks, 9 failures; HEAD:  / 91 checks, 9 failures |

The Inventory extra source-contract failure was fixed by checking the extracted shared InventoryListSql as well as the repository; final result is 112/113, with only the same baseline migration-count assertion failing. Transfer, module authorization, Procurement receiving, Sales hierarchy and geofence failures also reproduce on the baseline. Permission-template tests require missing tenant fixtures. The broad runner stops on the same warehouse fixture/PDO prerequisite after 91 checks on both identical clones. Its earlier shared-fixture run reached 246 checks/12 failures; these different endpoints are recorded rather than misrepresented as one clean run.

The cumulative-incentive regression suite is BLOCKED by its dedicated `office_app_cumulative_test` database/company-2/user-122/user-123 prerequisites. No DSA policy was altered to force it to pass. Therefore this is a completed list/compatibility implementation, **not certification that all production regressions pass**.

PHP lint: **228 changed/new PHP files passed**, 0 errors. `git diff --check`: exit 0. Duplicate controller methods: 0. Changed view/JS/CSS mojibake scan: 0 findings. Historical migrations 088/097/098 unchanged: True. Scope, dropdown, old pagination/cap, client filter and navigation scans are documented in the audit. Exports retain code strings (including leading zeroes) and native numeric/date/time cells; formula injection is guarded by existing codecs.

## Current Git state and exact file list

169 tracked modified files; 63 new upgrade files; 40 other untracked files preserved; 0 staged. The UI's recent-file indicator is not the full Git working-tree count. The list below is cumulative current work, including changes already present before this continuation.

| State | Repository-relative file |
|---|---|
| Modified | app/controllers/AssetController.php |
| Modified | app/controllers/AttendanceController.php |
| Modified | app/controllers/AttendanceSelfServiceController.php |
| Modified | app/controllers/AuditLogController.php |
| Modified | app/controllers/BranchController.php |
| Modified | app/controllers/CompanyAdministrationController.php |
| Modified | app/controllers/DataExchangeController.php |
| Modified | app/controllers/DepartmentController.php |
| Modified | app/controllers/EmployeeActivityController.php |
| Modified | app/controllers/FinanceAccountingController.php |
| Modified | app/controllers/FinanceBankReconciliationController.php |
| Modified | app/controllers/FinanceController.php |
| Modified | app/controllers/FinanceExpenseController.php |
| Modified | app/controllers/FinanceStaffLoanController.php |
| Modified | app/controllers/HrController.php |
| Modified | app/controllers/IntegrationEventController.php |
| Modified | app/controllers/InventoryController.php |
| Modified | app/controllers/JobTitleController.php |
| Modified | app/controllers/LeaveBalanceController.php |
| Modified | app/controllers/LeaveController.php |
| Modified | app/controllers/LeavePolicyController.php |
| Modified | app/controllers/ManagerWorkspaceController.php |
| Modified | app/controllers/PositionController.php |
| Modified | app/controllers/ProcurementController.php |
| Modified | app/controllers/RoleAdministrationController.php |
| Modified | app/controllers/SalesController.php |
| Modified | app/controllers/SalesIncentiveController.php |
| Modified | app/controllers/SalesPricingController.php |
| Modified | app/controllers/SalesProductVariantController.php |
| Modified | app/controllers/SalesReportController.php |
| Modified | app/controllers/SalesSettlementController.php |
| Modified | app/controllers/SalesStockHistoryController.php |
| Modified | app/controllers/StockRequestController.php |
| Modified | app/controllers/UserActivityController.php |
| Modified | app/controllers/UserAdministrationController.php |
| Modified | app/controllers/WarehouseController.php |
| Modified | app/controllers/WarehouseLocationController.php |
| Modified | app/controllers/WorkforceCalendarController.php |
| Modified | app/repositories/MySql/AssetRepository.php |
| Modified | app/repositories/MySql/AttendanceRepository.php |
| Modified | app/repositories/MySql/BranchRepository.php |
| Modified | app/repositories/MySql/CompanyMembershipRepository.php |
| Modified | app/repositories/MySql/EmployeePositionAssignmentRepository.php |
| Modified | app/repositories/MySql/EmployeeRepository.php |
| Modified | app/repositories/MySql/FinanceRepository.php |
| Modified | app/repositories/MySql/InventoryRepository.php |
| Modified | app/repositories/MySql/JobTitleRepository.php |
| Modified | app/repositories/MySql/SalesRepository.php |
| Modified | app/repositories/MySql/SettlementRepository.php |
| Modified | app/repositories/MySql/UserActivityRepository.php |
| Modified | app/repositories/MySql/WarehouseLocationRepository.php |
| Modified | app/repositories/MySql/WarehouseRepository.php |
| Modified | app/services/AccountingPeriodService.php |
| Modified | app/services/AssetService.php |
| Modified | app/services/AttendanceManagementService.php |
| Modified | app/services/AttendanceSelfServiceService.php |
| Modified | app/services/AuditLogAdministrationService.php |
| Modified | app/services/CompanyProvisioningService.php |
| Modified | app/services/DataExchange/ExportDataProvider.php |
| Modified | app/services/DataExchange/ExportDefinitionRegistry.php |
| Modified | app/services/DataExchange/ExportService.php |
| Modified | app/services/DataExchange/ImportResult.php |
| Modified | app/services/DataExchange/ImportService.php |
| Modified | app/services/DataExchange/ImportValidator.php |
| Modified | app/services/DataExchange/SchemaRegistry.php |
| Modified | app/services/DataExchange/SpreadsheetCodec.php |
| Modified | app/services/EmployeeActivityService.php |
| Modified | app/services/EmployeeDirectoryService.php |
| Modified | app/services/EmployeePositionAssignmentService.php |
| Modified | app/services/FinanceAccountingWorkspaceService.php |
| Modified | app/services/FinanceBankReconciliationService.php |
| Modified | app/services/FinanceDashboardService.php |
| Modified | app/services/FinanceExpenseService.php |
| Modified | app/services/FinanceOperationsService.php |
| Modified | app/services/FinanceStaffLoanService.php |
| Modified | app/services/FinanceStatementService.php |
| Modified | app/services/IntegrationEventOperationsService.php |
| Modified | app/services/InventoryService.php |
| Modified | app/services/LeaveBalanceManagementService.php |
| Modified | app/services/LeaveManagementService.php |
| Modified | app/services/ManagerWorkspaceService.php |
| Modified | app/services/ProcurementService.php |
| Modified | app/services/QuickSaleRouting.php |
| Modified | app/services/RoleAdministrationService.php |
| Modified | app/services/SalesIncentiveService.php |
| Modified | app/services/SalesPerformanceReportService.php |
| Modified | app/services/SalesPricingService.php |
| Modified | app/services/SalesProductVariantService.php |
| Modified | app/services/SalesQuickSaleService.php |
| Modified | app/services/SalesService.php |
| Modified | app/services/SalesStockHistoryService.php |
| Modified | app/services/SettlementService.php |
| Modified | app/services/StockRequestPeerWorkflow.php |
| Modified | app/services/StockRequestService.php |
| Modified | app/services/UserActivityService.php |
| Modified | app/services/UserAdministrationService.php |
| Modified | app/services/WarehouseLocationManagementService.php |
| Modified | app/services/WarehouseManagementService.php |
| Modified | app/services/WorkforceCalendarService.php |
| Modified | public/assets/css/app.css |
| Modified | public/assets/js/sales-pricing.js |
| Modified | resources/views/administration/audit-logs/index.php |
| Modified | resources/views/administration/companies/index.php |
| Modified | resources/views/administration/companies/show.php |
| Modified | resources/views/administration/integration-events.php |
| Modified | resources/views/administration/roles/index.php |
| Modified | resources/views/administration/roles/show.php |
| Modified | resources/views/administration/users/activity.php |
| Modified | resources/views/administration/users/index.php |
| Modified | resources/views/assets/index.php |
| Modified | resources/views/assets/show.php |
| Modified | resources/views/attendance/calendars/index.php |
| Modified | resources/views/attendance/index.php |
| Modified | resources/views/attendance/self/index.php |
| Modified | resources/views/attendance/team/index.php |
| Modified | resources/views/data-exchange/export.php |
| Modified | resources/views/data-exchange/import.php |
| Modified | resources/views/finance/accounting-periods.php |
| Modified | resources/views/finance/accounting-workspace.php |
| Modified | resources/views/finance/bank-reconciliation-worksheet.php |
| Modified | resources/views/finance/bank-reconciliation.php |
| Modified | resources/views/finance/customer-invoice.php |
| Modified | resources/views/finance/customer-invoices.php |
| Modified | resources/views/finance/expenses.php |
| Modified | resources/views/finance/index.php |
| Modified | resources/views/finance/quick-sale-queue.php |
| Modified | resources/views/finance/staff-loan.php |
| Modified | resources/views/finance/staff-loans.php |
| Modified | resources/views/finance/statement.php |
| Modified | resources/views/hr/departments/index.php |
| Modified | resources/views/hr/employees/activity.php |
| Modified | resources/views/hr/index.php |
| Modified | resources/views/hr/leave/balances/index.php |
| Modified | resources/views/hr/leave/index.php |
| Modified | resources/views/hr/leave/policies/index.php |
| Modified | resources/views/hr/show.php |
| Modified | resources/views/hr/team/index.php |
| Modified | resources/views/inventory/index.php |
| Modified | resources/views/inventory/locations/index.php |
| Modified | resources/views/inventory/receipts.php |
| Modified | resources/views/inventory/stock-daily-history.php |
| Modified | resources/views/inventory/stock-requests.php |
| Modified | resources/views/inventory/transfers.php |
| Modified | resources/views/inventory/warehouses/index.php |
| Modified | resources/views/layouts/module-navigation.php |
| Modified | resources/views/organization/branches/index.php |
| Modified | resources/views/organization/departments/index.php |
| Modified | resources/views/organization/job-titles/index.php |
| Modified | resources/views/organization/positions/index.php |
| Modified | resources/views/procurement/index.php |
| Modified | resources/views/procurement/requisition.php |
| Modified | resources/views/sales/commercial.php |
| Modified | resources/views/sales/deliveries.php |
| Modified | resources/views/sales/delivery.php |
| Modified | resources/views/sales/dsa-dsp-report.php |
| Modified | resources/views/sales/incentive-detail.php |
| Modified | resources/views/sales/incentives.php |
| Modified | resources/views/sales/index.php |
| Modified | resources/views/sales/order.php |
| Modified | resources/views/sales/pricing.php |
| Modified | resources/views/sales/product-variants.php |
| Modified | resources/views/sales/quick-sale-manager.php |
| Modified | resources/views/sales/quick-sale-routing.php |
| Modified | resources/views/sales/quick-sale.php |
| Modified | resources/views/sales/settlement.php |
| Modified | resources/views/sales/settlements.php |
| Modified | tests/data-exchange-integration.php |
| Modified | tests/data-exchange.php |
| Modified | tests/inventory-module-contract.php |
| New upgrade | app/services/DataExchange/HrImportService.php |
| New upgrade | app/services/DataExchange/ImportConfirmation.php |
| New upgrade | app/services/DataExchange/MasterImportValidator.php |
| New upgrade | app/services/Lists/AdministrationListService.php |
| New upgrade | app/services/Lists/AssetListService.php |
| New upgrade | app/services/Lists/AssetListSql.php |
| New upgrade | app/services/Lists/CalendarListService.php |
| New upgrade | app/services/Lists/DocumentListService.php |
| New upgrade | app/services/Lists/FilterOptions.php |
| New upgrade | app/services/Lists/FinanceListService.php |
| New upgrade | app/services/Lists/FinanceLoanListService.php |
| New upgrade | app/services/Lists/FinanceQuickSaleListService.php |
| New upgrade | app/services/Lists/FinanceStatementListService.php |
| New upgrade | app/services/Lists/HrListService.php |
| New upgrade | app/services/Lists/HrWorkspaceListService.php |
| New upgrade | app/services/Lists/InventoryListService.php |
| New upgrade | app/services/Lists/InventoryListSql.php |
| New upgrade | app/services/Lists/LeaveListService.php |
| New upgrade | app/services/Lists/ListDownload.php |
| New upgrade | app/services/Lists/ListQuery.php |
| New upgrade | app/services/Lists/OrganizationListService.php |
| New upgrade | app/services/Lists/PersonalAttendanceListService.php |
| New upgrade | app/services/Lists/ProcurementListService.php |
| New upgrade | app/services/Lists/ProcurementWorkspaceListService.php |
| New upgrade | app/services/Lists/QuickSaleListService.php |
| New upgrade | app/services/Lists/SalesListService.php |
| New upgrade | app/services/Lists/SalesScopeSql.php |
| New upgrade | app/services/Lists/SettlementListService.php |
| New upgrade | app/services/Lists/SqlList.php |
| New upgrade | app/services/Lists/StockHistoryListService.php |
| New upgrade | app/services/Lists/StockRequestLists.php |
| New upgrade | database/migrations/mysql/099_sales_manager_incentive_settlement_permission.php |
| New upgrade | docs/SMART_LIST_AUDIT.md |
| New upgrade | docs/SMART_LIST_IMPLEMENTATION_REPORT.md |
| New upgrade | resources/views/administration/list-controls.php |
| New upgrade | resources/views/assets/categories.php |
| New upgrade | resources/views/assets/list-controls.php |
| New upgrade | resources/views/attendance/calendars/list-controls.php |
| New upgrade | resources/views/components/document-list-controls.php |
| New upgrade | resources/views/components/document-list.php |
| New upgrade | resources/views/components/list-download.php |
| New upgrade | resources/views/components/list-filters.php |
| New upgrade | resources/views/components/list-pagination.php |
| New upgrade | resources/views/finance/list-controls.php |
| New upgrade | resources/views/finance/register.php |
| New upgrade | resources/views/inventory/list-controls.php |
| New upgrade | resources/views/procurement/list-controls.php |
| New upgrade | resources/views/sales/quick-sale-list-controls.php |
| New upgrade | tests/smart-list-access.php |
| New upgrade | tests/smart-list-administration.php |
| New upgrade | tests/smart-list-assets.php |
| New upgrade | tests/smart-list-attendance-periods.php |
| New upgrade | tests/smart-list-compatibility.php |
| New upgrade | tests/smart-list-documents.php |
| New upgrade | tests/smart-list-filter-options.php |
| New upgrade | tests/smart-list-finance.php |
| New upgrade | tests/smart-list-imports.php |
| New upgrade | tests/smart-list-inventory.php |
| New upgrade | tests/smart-list-procurement.php |
| New upgrade | tests/smart-list-sales.php |
| New upgrade | tests/smart-list-supporting.php |
| New upgrade | tests/smart-lists.php |
| New upgrade | tests/support/licensed-import-fixture.php |

Other untracked files left untouched:

- `.tmp-quick-sale-1.js`
- `.tmp-stock-requests-repaired-final.php`
- `.tmp-stock-requests-repaired.php`
- `before-bi-baseline-072.sql`
- `build-093-096-sql.php`
- `central-replenishment-fix.patch`
- `central-replenishment-full-diff.txt`
- `database/recovery/production_master_and_reference_recovery.sql`
- `docs/PRODUCTION_MASTER_AND_REFERENCE_RECOVERY_AUDIT.md`
- `employee-self-service-audit.txt`
- `local-before-production-sync.sql`
- `migration061-changes.patch`
- `notification-core-review.txt`
- `officeapp-074-deploy.tar.gz`
- `officeapp-074-final.tar.gz`
- `officeapp-074-production.sql`
- `officeapp-cumulative-incentive-36f6005.tar.gz`
- `officeapp-incentive-float-40dda7f.tar.gz`
- `officeapp-incentive-settlement-ui-6787559.tar.gz`
- `officeapp-migrations-093-096.sql`
- `officeapp-ui-hotfix-fcebff3.tar.gz`
- `officeapp-upgrade-086-091-e40fe8b.tar.gz`
- `officeapp-upgrade-092-c2d9016.tar.gz`
- `officeapp-upgrade-093-096-5b4728b.tar.gz`
- `officeapp-upgrade-from-fc673f8-to-e40fe8b.tar.gz`
- `pre-stock-request-074.sql`
- `procurement-prepatch-full-check.txt`
- `procurement-rejection-review.txt`
- `quick-sale-test-receipt.png`
- `sales-rejection-review.txt`
- `sales-team-scope-app.tar.gz`
- `sales-team-smart-ui.tar.gz`
- `sales-team-ui.tar.gz`
- `stock-hierarchy-082-audit.txt`
- `stock-request-implementation.patch`
- `tests/module-role-entitlements.php`
- `work/finance-accounting-smoke.php`
- `work/finance-bank-list-smoke.php`
- `work/finance-list-smoke.php`
- `work/finance-staff-loan-smoke.php`

COMMITTED: NO

PUSHED: NO

DEPLOYED: NO
