# OfficeApp ERP smart-list audit — current uncommitted tree

Date: 2026-09-28. Repository: `C:\Users\hp\muluneh\office-app-erp`. HEAD: `6787559`. This replaces the obsolete partial checkpoint.

Discovery inspected the runtime route registry (351 total routes; 147 GET), WorkspaceAccessService navigation, controllers, all 103 view files containing tables/iterations, and service/repository call paths. The matrix contains 120 query/register definitions, including shared aliases and independently paged child histories. It does not count aliases as additional implementations.

Modules: HR/Organization, Attendance, Sales, Inventory, Procurement, Finance, Assets, Administration; Dashboard, Analytics, API, account/security, Data Exchange and document screens classified below.

Every COMPLETE row uses SQL scope → search/filter → count → stable sort → page, 25/50/100 sizes, Showing X–Y of Z, query persistence and Clear Filters. Both CSV and XLSX reuse the same authorized filtered source and export all matching rows up to the explicit 10,000-row safety limit; larger results fail with an instruction to narrow filters, never silently truncate. Export requires the existing module export grant. Child registers authorize their parent first. Independent lists use namespaces and explicit export targets. Dynamic select options use the full authorized source, not the visible page. Finite statuses come from current workflow/CHECK/ENUM definitions; All is the shared first option.

COMPLETE means the listed register implementation and focused validation passed. It does not mean the entire production regression suite is green: existing baseline failures and the cumulative-incentive fixture blocker are recorded in the implementation report.

## Register matrix

The next two tables join by ID. This split keeps route/authorization evidence separate from detailed filter configuration without omitting required fields. All paths omit the common `/office_app/public` prefix. Source classes are under `app/controllers` and `app/services` (list services under `app/services/Lists`). Test paths are under `tests`.

| ID | Module / screen | Route | Controller | Service | Import | Authorization | Tests | Status |
|---|---|---|---|---|---|---|---|---|
| R001 | Sales / Customers | /sales/customers | SalesController | SalesListService | Create/update with preview + confirmation | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R002 | Sales / Products | /sales/products | SalesController | SalesListService | Create/update with preview + confirmation | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R003 | Sales / Sales Orders | /sales/orders; /sales | SalesController | SalesListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R004 | Sales / Quotations | /sales/quotations | SalesController | SalesListService | Create/update with preview + confirmation | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R005 | Sales / Sales Teams | /sales/teams | SalesController | SalesListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R006 | Sales / Pricelists | /sales/pricelists | SalesController | SalesListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R007 | Sales / Serials | /sales/products; /sales/teams (serials) | SalesController | SalesListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R008 | Sales / Commissions | /sales/products; /sales/teams (commissions) | SalesController | SalesListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R009 | Sales / Targets | /sales/products; /sales/teams (targets) | SalesController | SalesListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R010 | Sales / Deliveries | /sales/deliveries | SalesController | SalesListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R011 | Sales / Returns | /data-exchange/returns/export (alias; delivery detail below) | SalesController | SalesListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R012 | Sales / Pricing | /sales/pricing; /sales/pricelists | SalesPricingController / SalesController | SalesListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R013 | Sales / Variants | /sales/product-variants | SalesProductVariantController | SalesListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R014 | Finance / Receivables | /finance?section=receivables; /finance dashboard | FinanceController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R015 | Finance / Invoices | /finance/customer-invoices | FinanceController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R016 | Finance / Receipts | /finance?section=receipts | FinanceController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R017 | Finance / Journals | /finance?section=journals | FinanceController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R018 | Finance / Expenses | /finance/expenses; /finance?section=expenses (compatibility) | FinanceExpenseController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R019 | Finance / Accounts | /finance/accounting/accounts | FinanceAccountingController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R020 | Finance / Ledger | /finance/accounting/ledger | FinanceAccountingController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R021 | Finance / Ar Aging | /finance/accounting/receivables | FinanceAccountingController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R022 | Finance / Payables | /finance/accounting/payables | FinanceAccountingController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R023 | Finance / Cash Bank | /finance/accounting/cash-bank | FinanceAccountingController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R024 | Finance / Staff Loans | /finance/staff-loans | FinanceStaffLoanController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R025 | Finance / Bank Mappings | /finance/bank-reconciliation (mappings) | FinanceBankReconciliationController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R026 | Finance / Bank Statements | /finance/bank-reconciliation (statements) | FinanceBankReconciliationController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R027 | Finance / Accounting Periods | /finance/accounting-periods (periods) | FinanceController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R028 | Finance / Fiscal Years | /finance/accounting-periods (years) | FinanceController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R029 | Finance / Period History | /finance/accounting-periods (history) | FinanceController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R030 | Finance / Expense Categories | /finance/expenses (categories) | FinanceExpenseController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R031 | Finance / Expense History | /finance/expenses (history) | FinanceExpenseController | FinanceListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R032 | Procurement / Suppliers | /procurement?section=suppliers | ProcurementController | ProcurementListService | Create only, preview + confirmation | Tenant + Procurement function permission; requisition/order and exact destination authority | smart-list-procurement.php | COMPLETE |
| R033 | Procurement / Requisitions | /procurement?section=requisitions | ProcurementController | ProcurementListService | No — workflow/configuration/history | Tenant + Procurement function permission; requisition/order and exact destination authority | smart-list-procurement.php | COMPLETE |
| R034 | Procurement / Purchase Orders | /procurement?section=orders; /procurement?section=overview | ProcurementController | ProcurementListService | No — workflow/configuration/history | Tenant + Procurement function permission; requisition/order and exact destination authority | smart-list-procurement.php | COMPLETE |
| R035 | Procurement / Bills | /procurement?section=bills; /procurement?section=payments | ProcurementController | ProcurementListService | No — workflow/configuration/history | Tenant + Procurement function permission; requisition/order and exact destination authority | smart-list-procurement.php | COMPLETE |
| R036 | Procurement / Returns | /procurement?section=returns | ProcurementController | ProcurementListService | No — workflow/configuration/history | Tenant + Procurement function permission; requisition/order and exact destination authority | smart-list-procurement.php | COMPLETE |
| R037 | Inventory / Stock | /inventory?section=stock | InventoryController | InventoryListService | No — workflow/configuration/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |
| R038 | Inventory / Movements | /inventory?section=movements | InventoryController | InventoryListService | No — workflow/configuration/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |
| R039 | Inventory / Receipts | /inventory/receipts | InventoryController | InventoryListService | No — workflow/configuration/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |
| R040 | Inventory / Transfers | /inventory/transfers | InventoryController | InventoryListService | No — workflow/configuration/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |
| R041 | Inventory / Warehouses | /inventory/warehouses | WarehouseController | InventoryListService | No — workflow/configuration/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |
| R042 | Inventory / Locations | /inventory/locations | WarehouseLocationController | InventoryListService | No — workflow/configuration/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |
| R043 | Assets / Register | /assets-management?section=register | AssetController | AssetListService | No — workflow/configuration/history | Tenant + Assets view/manage and authorized asset parent | smart-list-assets.php | COMPLETE |
| R044 | Assets / Categories | /assets-management?section=categories | AssetController | AssetListService | No — workflow/configuration/history | Tenant + Assets view/manage and authorized asset parent | smart-list-assets.php | COMPLETE |
| R045 | Assets / Schedule | /assets-management/{id} | AssetController | AssetListService | No — workflow/configuration/history | Tenant + Assets view/manage and authorized asset parent | smart-list-assets.php | COMPLETE |
| R046 | Assets / Transfers | /assets-management/{id} | AssetController | AssetListService | No — workflow/configuration/history | Tenant + Assets view/manage and authorized asset parent | smart-list-assets.php | COMPLETE |
| R047 | Assets / Maintenance | /assets-management/{id} | AssetController | AssetListService | No — workflow/configuration/history | Tenant + Assets view/manage and authorized asset parent | smart-list-assets.php | COMPLETE |
| R048 | Assets / History | /assets-management/{id} | AssetController | AssetListService | No — workflow/configuration/history | Tenant + Assets view/manage and authorized asset parent | smart-list-assets.php | COMPLETE |
| R049 | Administration / Users | /administration/users | UserAdministrationController | AdministrationListService | No — workflow/configuration/history | Effective administration/audit permission; company scope, or explicit platform administrator for company catalogue and its members | smart-list-administration.php | COMPLETE |
| R050 | Administration / Companies | /administration/companies | CompanyAdministrationController | AdministrationListService | No — workflow/configuration/history | Effective administration/audit permission; company scope, or explicit platform administrator for company catalogue and its members | smart-list-administration.php | COMPLETE |
| R051 | Administration / Company Users | /administration/companies/view | CompanyAdministrationController | AdministrationListService | No — workflow/configuration/history | Effective administration/audit permission; company scope, or explicit platform administrator for company catalogue and its members | smart-list-administration.php | COMPLETE |
| R052 | Administration / Roles | /administration/roles | RoleAdministrationController | AdministrationListService | No — workflow/configuration/history | Effective administration/audit permission; company scope, or explicit platform administrator for company catalogue and its members | smart-list-administration.php | COMPLETE |
| R053 | Administration / Role Users | /administration/roles/view (users) | RoleAdministrationController | AdministrationListService | No — workflow/configuration/history | Effective administration/audit permission; company scope, or explicit platform administrator for company catalogue and its members | smart-list-administration.php | COMPLETE |
| R054 | Administration / Role Permissions | /administration/roles/view (permissions) | RoleAdministrationController | AdministrationListService | No — workflow/configuration/history | Effective administration/audit permission; company scope, or explicit platform administrator for company catalogue and its members | smart-list-administration.php | COMPLETE |
| R055 | Administration / Audit | /administration/audit-logs | AuditLogController | AdministrationListService | No — workflow/configuration/history | Effective administration/audit permission; company scope, or explicit platform administrator for company catalogue and its members | smart-list-administration.php | COMPLETE |
| R056 | Administration / Events | /administration/integration-events | IntegrationEventController | AdministrationListService | No — workflow/configuration/history | Effective administration/audit permission; company scope, or explicit platform administrator for company catalogue and its members | smart-list-administration.php | COMPLETE |
| R057 | Administration / Employee Activity | /hr/employees/activity | EmployeeActivityController | AdministrationListService | No — workflow/configuration/history | Effective administration/audit permission; company scope, or explicit platform administrator for company catalogue and its members | smart-list-administration.php | COMPLETE |
| R058 | Administration / User Activity | /administration/users/activity | UserActivityController | AdministrationListService | No — workflow/configuration/history | Effective administration/audit permission; company scope, or explicit platform administrator for company catalogue and its members | smart-list-administration.php | COMPLETE |
| R059 | HR / Organization / Branches | /organization/branches | BranchController | OrganizationListService | No — workflow/configuration/history | Tenant + HR/organization permission; authorized employee or direct-manager/self scope where specified | smart-list-supporting.php | COMPLETE |
| R060 | HR / Organization / Departments | /organization/departments; /hr/departments | DepartmentController / HrController | OrganizationListService | No — workflow/configuration/history | Tenant + HR/organization permission; authorized employee or direct-manager/self scope where specified | smart-list-supporting.php | COMPLETE |
| R061 | HR / Organization / Job Titles | /organization/job-titles | JobTitleController | OrganizationListService | No — workflow/configuration/history | Tenant + HR/organization permission; authorized employee or direct-manager/self scope where specified | smart-list-supporting.php | COMPLETE |
| R062 | HR / Organization / Positions | /organization/positions | PositionController | OrganizationListService | No — workflow/configuration/history | Tenant + HR/organization permission; authorized employee or direct-manager/self scope where specified | smart-list-supporting.php | COMPLETE |
| R063 | HR / Organization / Leave Policies | /hr/leave/policies | LeavePolicyController | OrganizationListService | No — workflow/configuration/history | Tenant + HR/organization permission; authorized employee or direct-manager/self scope where specified | smart-list-supporting.php | COMPLETE |
| R064 | Finance / Installments | /finance/staff-loans/{id} | FinanceStaffLoanController | FinanceLoanListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R065 | Finance / Payments | /finance/staff-loans/{id} | FinanceStaffLoanController | FinanceLoanListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R066 | Finance / History | /finance/staff-loans/{id} | FinanceStaffLoanController | FinanceLoanListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R067 | Inventory / Requests | /inventory/stock-requests | StockRequestController | StockRequestLists | No — workflow/configuration/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |
| R068 | Inventory / Peers | /inventory/stock-requests | StockRequestController | StockRequestLists | No — workflow/configuration/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |
| R069 | Inventory / Authorities | /inventory/stock-requests | StockRequestController | StockRequestLists | No — workflow/configuration/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |
| R070 | Inventory / Reorder | /inventory/stock-requests | StockRequestController | StockRequestLists | No — workflow/configuration/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |
| R071 | Inventory / Events | /inventory/stock-requests/{id} | StockRequestController | StockRequestLists | No — workflow/configuration/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |
| R072 | Inventory / Allocations | /inventory/stock-requests/{id} | StockRequestController | StockRequestLists | No — workflow/configuration/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |
| R073 | Inventory / Procurements | /inventory/stock-requests/{id} | StockRequestController | StockRequestLists | No — workflow/configuration/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |
| R074 | HR / Organization / Employee Positions | /hr/employees/view | HrController | DocumentListService | No — workflow/configuration/history | Tenant + HR/organization permission; authorized employee or direct-manager/self scope where specified; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-supporting.php | COMPLETE |
| R075 | HR / Organization / Employee Reports | /hr/employees/view | HrController | DocumentListService | No — workflow/configuration/history | Tenant + HR/organization permission; authorized employee or direct-manager/self scope where specified; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-supporting.php | COMPLETE |
| R076 | Sales / Incentive Settlements | /sales/incentives/{id} | SalesIncentiveController | DocumentListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R077 | Sales / Incentive Events | /sales/incentives/{id} | SalesIncentiveController | DocumentListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R078 | Finance / Settlement Confirmations | /sales/settlements/{id}; /finance/settlements/{id} | SalesSettlementController | DocumentListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R079 | Finance / Settlement Events | /sales/settlements/{id}; /finance/settlements/{id} | SalesSettlementController | DocumentListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R080 | Sales / Pricing Rules | /sales/pricelists/{id} | SalesController | DocumentListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R081 | Sales / Delivery Returns | /sales/deliveries/{id} | SalesController | DocumentListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R082 | Sales / Order Pickings | /sales/orders/{id} | SalesController | DocumentListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R083 | Sales / Order Invoices | /sales/orders/{id} | SalesController | DocumentListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R084 | Sales / Order Revisions | /sales/orders/{id} | SalesController | DocumentListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R085 | Procurement / Requisition History | /procurement/requisitions/{id} | ProcurementController | DocumentListService | No — workflow/configuration/history | Tenant + Procurement function permission; requisition/order and exact destination authority; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R086 | Finance / Bank Accounts | /sales/settlements; /finance/settlements | SalesSettlementController | DocumentListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R087 | Sales / Team Members | /sales/teams/{id} | SalesController | DocumentListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R088 | Finance / Invoice Payments | /finance/customer-invoices/{id} | FinanceController | DocumentListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R089 | Procurement / Purchase Receipts | /procurement/{id} | ProcurementController | DocumentListService | No — workflow/configuration/history | Tenant + Procurement function permission; requisition/order and exact destination authority; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R090 | Procurement / Purchase Bills | /procurement/{id} | ProcurementController | DocumentListService | No — workflow/configuration/history | Tenant + Procurement function permission; requisition/order and exact destination authority; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R091 | Procurement / Purchase Returns | /procurement/{id} | ProcurementController | DocumentListService | No — workflow/configuration/history | Tenant + Procurement function permission; requisition/order and exact destination authority; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R092 | Sales / Quick Sale Events | /sales/quick-sale/{id} | SalesController | DocumentListService | No — workflow/configuration/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R093 | Finance / Bank Matches | /finance/bank-reconciliation/{id} | FinanceBankReconciliationController | DocumentListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R094 | Finance / Bank Events | /finance/bank-reconciliation/{id} | FinanceBankReconciliationController | DocumentListService | No — workflow/configuration/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable; domain service authorizes parent BEFORE child query; tenant + parent predicates retained | smart-list-documents.php | COMPLETE |
| R095 | HR / Organization / Employees | /hr | HrController | HrListService | Create only + preview + explicit confirmation | Tenant + HR/organization permission; authorized employee or direct-manager/self scope where specified | smart-lists.php | COMPLETE |
| R096 | Attendance / Attendance — daily / weekly / monthly | /attendance | AttendanceController | HrListService | Create only; employee/date duplicate protection | Tenant + attendance permission; own employee or direct-manager scope on self/team pages | smart-list-attendance-periods.php | COMPLETE |
| R097 | HR / Organization / Leave requests — company / team / self | /hr/leave | LeaveController | LeaveListService | No — workflow/history | Tenant + HR/organization permission; authorized employee or direct-manager/self scope where specified | smart-list-supporting.php | COMPLETE |
| R098 | HR / Organization / Direct team | /hr/team | ManagerWorkspaceController | HrWorkspaceListService | No — workflow/history | Tenant + HR/organization permission; authorized employee or direct-manager/self scope where specified; active direct reporting memberships only | smart-list-supporting.php | COMPLETE |
| R099 | Attendance / Attendance team | /attendance/team | AttendanceSelfServiceController | HrWorkspaceListService | No — workflow/history | Tenant + attendance permission; own employee or direct-manager scope on self/team pages; active direct reporting memberships only | smart-list-supporting.php | COMPLETE |
| R100 | HR / Organization / Leave balances | /hr/leave/balances | LeaveBalanceController | HrWorkspaceListService | No — workflow/history | Tenant + HR/organization permission; authorized employee or direct-manager/self scope where specified | smart-list-supporting.php | COMPLETE |
| R101 | HR / Organization / Leave adjustments | /hr/leave/balances | LeaveBalanceController | HrWorkspaceListService | No — workflow/history | Tenant + HR/organization permission; authorized employee or direct-manager/self scope where specified | smart-list-supporting.php | COMPLETE |
| R102 | Attendance / Calendar catalogue | /attendance/calendars | WorkforceCalendarController | CalendarListService | No — workflow/history | Tenant + attendance permission; own employee or direct-manager scope on self/team pages | smart-list-supporting.php | COMPLETE |
| R103 | Attendance / Calendar holidays | /attendance/calendars | WorkforceCalendarController | CalendarListService | No — workflow/history | Tenant + attendance permission; own employee or direct-manager scope on self/team pages | smart-list-supporting.php | COMPLETE |
| R104 | Attendance / Employee schedule assignments | /attendance/calendars | WorkforceCalendarController | HrWorkspaceListService | No — workflow/history | Tenant + attendance permission; own employee or direct-manager scope on self/team pages | smart-list-supporting.php | COMPLETE |
| R105 | Attendance / My attendance history | /attendance/me | AttendanceSelfServiceController | PersonalAttendanceListService | No — workflow/history | Tenant + attendance permission; own employee or direct-manager scope on self/team pages; logged-in employee only | smart-list-supporting.php | COMPLETE |
| R106 | Sales / Selling-price change history | /sales/pricing; /sales/pricelists | SalesPricingController / SalesController | SalesPricingService | No — workflow/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R107 | Sales / Quick Sale tasks | /sales/quick-sale | SalesController | QuickSaleListService | No — workflow/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export; self tasks/history, assigned-manager queue/corrections/history, permitted reporting tree for hierarchy | smart-list-sales.php | COMPLETE |
| R108 | Sales / Quick Sale queue | /sales/quick-sale | SalesController | QuickSaleListService | No — workflow/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export; self tasks/history, assigned-manager queue/corrections/history, permitted reporting tree for hierarchy | smart-list-sales.php | COMPLETE |
| R109 | Sales / Quick Sale waiting | /sales/quick-sale | SalesController | QuickSaleListService | No — workflow/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export; self tasks/history, assigned-manager queue/corrections/history, permitted reporting tree for hierarchy | smart-list-sales.php | COMPLETE |
| R110 | Sales / Quick Sale history | /sales/quick-sale | SalesController | QuickSaleListService | No — workflow/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export; self tasks/history, assigned-manager queue/corrections/history, permitted reporting tree for hierarchy | smart-list-sales.php | COMPLETE |
| R111 | Sales / Quick Sale hierarchy | /sales/quick-sale | SalesController | QuickSaleListService | No — workflow/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export; self tasks/history, assigned-manager queue/corrections/history, permitted reporting tree for hierarchy | smart-list-sales.php | COMPLETE |
| R112 | Sales / DSA/DSP grouped report | /sales/dsa-dsp-report | SalesReportController | SalesPerformanceReportService | No — workflow/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R113 | Sales / Incentive claims | /sales/incentives | SalesIncentiveController | SalesIncentiveService | No — workflow/history | Tenant + effective Sales permission; order/customer/agent hierarchy, delivery warehouse authority; same predicates for options and export | smart-list-sales.php | COMPLETE |
| R114 | Finance / Shared settlement register | /sales/settlements; /finance/settlements | SalesSettlementController | SettlementListService | No — workflow/history | Tenant + existing Finance settlement grant or Sales hierarchy; identical list/export ownership and reconciliation predicates | smart-list-sales.php | COMPLETE |
| R115 | Finance / Quick Sale invoice handoff queue | /finance/customer-invoices | FinanceController | FinanceQuickSaleListService | No — workflow/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable | smart-list-finance.php | COMPLETE |
| R116 | Finance / Customer statement activity | /finance/statements/customer | FinanceAccountingController | FinanceStatementListService | No — workflow/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable; party validated in tenant first; running balances use whole chronological ledger, not visible page | smart-list-finance.php | COMPLETE |
| R117 | Finance / Supplier statement activity | /finance/statements/supplier | FinanceAccountingController | FinanceStatementListService | No — workflow/history | Tenant + effective Finance function permission; expense requester/approver and authorized parent where applicable; party validated in tenant first; running balances use whole chronological ledger, not visible page | smart-list-finance.php | COMPLETE |
| R118 | Inventory / Daily stock history stock | /inventory/stock-daily-history | SalesStockHistoryController | StockHistoryListService | No — workflow/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |
| R119 | Inventory / Daily stock history legs | /inventory/stock-daily-history | SalesStockHistoryController | StockHistoryListService | No — workflow/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |
| R120 | Inventory / Daily stock history issues | /inventory/stock-daily-history | SalesStockHistoryController | StockHistoryListService | No — workflow/history | Tenant + Inventory function permission + authorized warehouse/location or requester/current-handler authority | smart-list-inventory.php | COMPLETE |

## Search, controls, sorting and export matrix

| ID | Search columns (SQL before count/page) | Filters / control types | Status options or source | Allowed sorts | Pagination / sizes / count | Excel | CSV |
|---|---|---|---|---|---|---|---|
| R001 | customer_number, name, legal_name, email, phone, mobile, tax_number, agent_name, team_name, status_label | active: select; type: select | active: 1, 0 | name, code, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R002 | sku, name, category, model_name, brand_name, status_label | active: select; category: select; type: select | active: 1, 0 | name, code, status, category, price | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R003 | order_number, customer_name, customer_number, agent_name, responsible_name, status, order_date | status: select; from: date; to: date | status: draft, submitted, confirmed, approved, partially_fulfilled, partially_paid, paid, fulfilled, cancelled, returned | date, reference, customer, status, total | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R004 | quotation_number, customer_name, customer_number, agent_name, responsible_name, status, quotation_date | status: select; from: date; to: date | status: draft, sent, confirmed, cancelled, expired | date, reference, customer, status, total | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R005 | name, manager_name, member_names | active: select | active: 1, 0 | name, manager, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R006 | name, currency | active: select; currency: select | active: 1, 0 | name, currency, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R007 | serial_number, sku, product_name, status | status: select | status: available, reserved, sold, returned, blocked | date, serial, product, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R008 | order_number, agent_code, agent_name, status | status: select | status: accrued, approved, paid, reversed | date, reference, agent, amount, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R009 | territory_name, agent_name, period_start, period_end | from: date; to: date | N/A — no status control | date, agent, territory, amount | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R010 | picking_number, order_number, customer_name, warehouse_name, warehouse_code, responsible_name, status, document_date | status: select; from: date; to: date; warehouse: select | status: draft, waiting_stock, ready, partially_done, done, cancelled | date, reference, customer, warehouse, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R011 | picking_number, order_number, customer_name, warehouse_name, warehouse_code, responsible_name, status, document_date | status: select; from: date; to: date; warehouse: select | status: draft, waiting_stock, ready, partially_done, done, cancelled | date, reference, customer, warehouse, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R012 | sku, name, category, model_name, brand_name, product_family, mifi_subtype, status_label | active: select; family: select; brand: select | active: 1, 0 | name, code, status, family, brand, model, price | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R013 | sku, name, category, model_name, brand_name, product_family, mifi_subtype, status_label | active: select; family: select; brand: select | active: 1, 0 | name, code, status, family, brand, model, price | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R014 | order_number, customer_number, customer_name, currency, list_status | status: select; currency: select; from: date; to: date | status: open, overdue, partially_paid, paid, cancelled | due_date, order, customer, balance, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R015 | invoice_number, customer_number, customer_name, order_number, currency, status, payment_status | status: select; payment: select; currency: select; customer: select; from: date; to: date | status: draft, posted, reversed, cancelled; payment: unpaid, partially_paid, paid, credit | date, invoice, customer, due_date, total, residual, status, payment | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R016 | receipt_number, reference_number, order_number, customer_number, customer_name, payment_method, currency | method: select; currency: select; from: date; to: date | N/A — no status control | date, receipt, order, customer, amount, method | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R017 | batch_number, source_type, source_number, description, currency, status | status: select; currency: select; source: select; from: date; to: date | status: draft, posted, reversed, cancelled | date, batch, source, type, debit, credit, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R018 | request_number, title, description, requester_name, category_code, category_name, currency, status | status: select; currency: select; category: select; from: date; to: date | status: draft, submitted, approved, rejected, paid, cancelled, reversed | date, request, title, requester, category, amount, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R019 | account_code, account_name, account_type, normal_balance, currency, system_key | type: select; currency: select; active: select | active: 1, 0 | code, name, type, currency, active | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R020 | batch_number, source_type, source_number, account_code, account_name, description, poster_name, currency | currency: select; source: select; account: select; from: date; to: date | N/A — no status control | date, batch, source, account, debit, credit | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R021 | invoice_number, customer_number, customer_name, currency, payment_status, aging_bucket | payment: select; aging: select; currency: select; from: date; to: date | payment: unpaid, partially_paid, paid, credit | due, date, invoice, customer, total, outstanding, payment, aging | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R022 | invoice_number, supplier_invoice_number, supplier_name, po_number, currency, payment_status, aging_bucket | payment: select; aging: select; currency: select; from: date; to: date | payment: unpaid, partially_paid, paid, credit | due, date, invoice, supplier, po, total, outstanding, payment, aging | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R023 | batch_number, source_type, source_number, account_code, account_name, description, currency | currency: select; account: select; from: date; to: date | N/A — no status control | date, batch, source, account, debit, credit | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R024 | loan_number, employee_number, employee_name, loan_type, purpose, currency, list_status, due_state | status: select; currency: select; loan_type: select; due_state: select; overdue: select; from: date; to: date | status: draft, submitted, approved, rejected, active, paid, cancelled | loan, employee, request_date, principal, paid, outstanding, next_due, overdue, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R025 | bank_name, bank_account_name, account_number, account_code, account_name, currency, status, cutover_reason, approver_name | status: select; currency: select; bank: select; from: date; to: date | status: draft, approved, superseded | effective, bank, account, gl, status, created | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R026 | statement_reference, bank_name, bank_account_name, account_number, account_code, gl_account_name, currency, reconciliation_status, preparer_name, reviewer_name | status: select; currency: select; bank: select; from: date; to: date | status: draft, in_review, completed, superseded | period_end, period_start, reference, bank, account, opening, ending, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R027 | period_name, fiscal_year_name, status | status: select; year: select; from: date; to: date | status: open, closed, locked | date, name, year, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R028 | fiscal_year_name, status | status: select; from: date; to: date | status: open, closed, locked | date, name, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R029 | period_name, action, reason, actor_name | action: select; from: date; to: date | N/A — no status control | date, period, action, actor | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R030 | code, name, expense_code, expense_name, tax_code, tax_name | active: select | active: 1, 0 | name, code, status, expense, tax | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R031 | request_number, title, action, reason, actor_name | status: select; action: select; from: date; to: date | status: draft, submitted, approved, rejected, paid, cancelled, reversed | date, expense, action, status, actor | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R032 | supplier_code, business_name, contact_person, phone, email, tax_number, currency | active: select; currency: select | active: 1, 0 | name, code, currency, status, date | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R033 | requisition_number, requester_name, stock_request_number, justification, status, document_date | status: select; from: date; to: date | status: draft, submitted, approved, rejected, converted, cancelled | date, reference, requester, required_by, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R034 | po_number, supplier_code, supplier_name, supplier_reference, warehouse_code, warehouse_name, destination_location_code, destination_location_name, status, currency, document_date | status: select; currency: select; from: date; to: date | status: draft, submitted, approved, confirmed, partially_received, received, partially_billed, billed, closed, rejected, cancelled | date, reference, supplier, expected, status, total | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R035 | invoice_number, supplier_invoice_number, supplier_code, supplier_name, po_number, status, payment_status, currency, document_date | status: select; payment: select; currency: select; from: date; to: date | status: draft, posted, reversed, cancelled; payment: unpaid, partially_paid, paid, credit | date, reference, supplier, po, status, outstanding, total | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R036 | return_number, po_number, supplier_code, supplier_name, warehouse_code, warehouse_name, reason, status, document_date | status: select; from: date; to: date | status: posted | date, reference, po, supplier, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R037 | sku, product_name, warehouse_code, warehouse_name, location_code, location_name | warehouse: select; location: select; from: date; to: date | N/A — no status control | product, sku, warehouse, location, quantity, date | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R038 | sku, product_name, reference_number, reference_type, movement_type, status, source_warehouse_code, source_warehouse_name, destination_warehouse_code, destination_warehouse_name, source_location_name, destination_location_name | status: select; type: select; source: select; destination: select; from: date; to: date | status: draft, ready, completed, cancelled | date, reference, sku, product, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R039 | receipt_number, supplier_name, supplier_reference, receipt_date, status, warehouse_code, warehouse_name, location_code, destination_location_name | status: select; warehouse: select; location: select; from: date; to: date | status: draft, submitted, approved, posted, cancelled | date, reference, supplier, warehouse, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R040 | transfer_number, source_warehouse_name, source_warehouse_code, destination_warehouse_name, destination_warehouse_code, status, document_date | status: select; source: select; destination: select; from: date; to: date | status: draft, submitted, approved, in_transit, done, cancelled | date, reference, source, destination, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R041 | code, name, warehouse_type, branch_name, manager_name, phone, email | active: select; type: select; branch: select | active: 1, 0 | name, code, branch, type, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R042 | code, name, warehouse_code, warehouse_name, parent_code, parent_name, barcode, location_type | active: select; warehouse: select; type: select | active: 1, 0 | name, code, warehouse, priority, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R043 | asset_number, asset_name, serial_number, category_code, category_name, custodian_name, department_name, location_name, vendor_name | status: select; category: select; department: select; location: select; presence: select; health: select; source: select; from: date; to: date | status: draft, active, fully_depreciated, under_maintenance, disposed, sold, scrapped, cancelled | number, name, date, category, custodian, value, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R044 | category_code, category_name | active: select | active: 1, 0 | code, name, life, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R045 | asset_number, batch_number, status | status: select; from: date; to: date | status: scheduled, posted, reversed, cancelled | period, date, amount, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R046 | asset_number, from_custodian, to_custodian, from_department, to_department, from_location_name, to_location_name, reason, actor_name | department: select; location: select; from: date; to: date | N/A — no status control | date, custodian, location | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R047 | asset_number, description, vendor_name, maintenance_type, actor_name | status: select; type: select; from: date; to: date | status: planned, in_progress, completed, cancelled | date, cost, type, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R048 | asset_number, action, actor_name, from_status, to_status | action: select; from: date; to: date | N/A — no status control | date, action, actor | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R049 | username, display_name, email | status: select; from: date; to: date | status: active, inactive, locked | username, display_name, email, last_login_at, created_at | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R050 | code, name, legal_name, contact_email, owner_name | status: select; approval: select; currency: select; from: date; to: date | status: pending, active, trial, expired, suspended, inactive; approval: approved, pending | name, code, created_at, expires | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R051 | username, display_name, email | status: select; from: date; to: date | status: active, inactive, locked | username, display_name, email, last_login_at, created_at | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R052 | code, name, description | active: select; system: select | active: 1, 0 | name, code, users, permissions | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R053 | username, display_name, email, assigned_by_name | active: select | active: 1, 0 | name, username, assigned | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R054 | code, name, description, module | active: select; module: select | active: 1, 0 | name, code, module | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R055 | action, module, table_name, actor_name, actor_username | module: select; action: select; actor: select; from: date; to: date | N/A — no status control | date, action, module, actor | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R056 | event_type, aggregate_type, event_id | status: select; event_type: select; source: select; from: date; to: date | status: pending, processing, processed, failed | date, type, status, attempts | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R057 | action, module, table_name, actor_name, actor_username | module: select; action: select; actor: select; from: date; to: date | N/A — no status control | date, action, module, actor | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R058 | action, category, actor_name, actor_username | type: select; source: select; from: date; to: date | N/A — no status control | date, action, actor | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R059 | code, name, contact_email, contact_phone, city, country_code | active: select | active: 1, 0 | name, code, status, updated | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R060 | code, name, parent_department_name, description | active: select | active: 1, 0 | name, code, status, updated | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R061 | code, name, job_family, grade_level, description | active: select | active: 1, 0 | name, code, status, updated, family | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R062 | code, name, branch_name, department_name, job_title_name, status | status: select | status: planned, open, frozen, closed | name, code, status, department, branch | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R063 | code, name, approval_workflow, hr_approver_name | active: select | active: 1, 0 | name, code, status, updated | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R064 | installment_number | status: select; from: date; to: date | status: paid, upcoming, partially_paid, overdue, due | number, date, amount, remaining, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R065 | loan_number, payment_number, reference_number, batch_number, poster_name | from: date; to: date | N/A — no status control | date, number, reference, amount | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R066 | loan_number, action, reason, actor_name | status: select; action: select; from: date; to: date | status: draft, submitted, approved, rejected, disbursed, active, paid, cancelled | date, action, actor, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R067 | request_number, requester_name, current_handler_name, serving_warehouse_name, serving_location_name, status, request_kind | status: select; kind: select; from: date; to: date | status: pending_review, awaiting_transfer, awaiting_procurement, ready_to_issue, issued, closed, cancelled, rejected | date, reference, requester, handler, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R068 | proposal_number, request_number, sku, product_name, source_name, destination_name, source_owner_name, destination_owner_name, proposer_name, state, transfer_number | status: select; from: date; to: date | status: proposed, source_approved, source_rejected, dispatched, completed, cancelled | date, reference, product, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R069 | display_name, job_title, warehouse_code, warehouse_name, location_code, location_name, manager_name, parent_warehouse_name | active: select; level: select | active: 1, 0 | manager, level, warehouse, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R070 | sku, name | low_stock: select | N/A — no status control | product, sku, available, threshold | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R071 | event_type, actor_name, reason | status: select; event: select; from: date; to: date | status: pending_review, awaiting_transfer, awaiting_procurement, ready_to_issue, issued, closed, cancelled, rejected | date, event, actor | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R072 | sku, product_name, authority_name, source_warehouse_name, source_location_name, destination_warehouse_name, destination_location_name, transfer_number | status: select; level: select | status: source_reserved, in_transit, shop_reserved, issued, released | product, manager, quantity, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R073 | requisition_number, po_number | status: select; purchase_status: select | status: draft, submitted, approved, rejected, converted, cancelled | requisition, purchase, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R074 | position_code_snapshot, position_name_snapshot, department_name_snapshot, job_title_name_snapshot, branch_name_snapshot, notes, assigned_by_name | status: select; department: select; branch: select; from: date; to: date | status: current, ended | date, position | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R075 | employee_number, displayName, job_title, department_name | status: select; department: select | status: authorized full-dataset/domain source; see service | name, number, department | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R076 | external_payment_reference, evidence_reference, entered_by_name | currency: select; from: date; to: date | N/A — no status control | date, amount | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R077 | event_type, reason_reference, actor_name | event: select; status: select; from: date; to: date | status: submitted, approved, rejected, partially_settled, settled | date, event | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R078 | bank_reference, creator_name | from: date; to: date | N/A — no status control | date, amount, reference | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R079 | action, reason, actor_name | action: select; status: select; from: date; to: date | status: draft, submitted, supervisor_reviewed, finance_reconciled, approved, closed, returned, cancelled, awaiting_confirmation, matched, partial, mismatch, review_required | date, action | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R080 | sku, product_name, category | active: select; calculation: select; category: select | active: 1, 0 | priority, minimum, product | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R081 | picking_number | status: select; from: date; to: date | status: draft, waiting_stock, ready, partially_done, done, cancelled | date, number | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R082 | picking_number | type: select; status: select; from: date; to: date | status: draft, waiting_stock, ready, partially_done, done, cancelled | date, number | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R083 | invoice_number | type: select; status: select; payment: select; from: date; to: date | status: draft, posted, reversed, cancelled; payment: unpaid, partially_paid, paid, credit | date, number, amount | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R084 | reason, actor_name, source_event | event: select; from: date; to: date | N/A — no status control | revision, date | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R085 | action, reason, actor_name | action: select; status: select; from: date; to: date | status: draft, submitted, approved, rejected, converted, cancelled | date, action | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R086 | bank_name, account_name, account_number, branch, swift_bic, provider_code | active: select; currency: select; bank: select | active: 1, 0 | bank, account, currency | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R087 | agent_code, name | type: select | N/A — no status control | name, code | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R088 | payment_number, reference_number, posting_reference | method: select; status: select; from: date; to: date | status: draft, posted, reversed, cancelled | date, number, amount | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R089 | receipt_number | status: select; from: date; to: date | status: draft, submitted, approved, posted, cancelled | date, number | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R090 | invoice_number, supplier_invoice_number | status: select; payment: select; type: select; from: date; to: date | status: draft, posted, reversed, cancelled; payment: unpaid, partially_paid, paid, credit | date, number, amount | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R091 | return_number, reason | status: select; from: date; to: date | status: authorized full-dataset/domain source; see service | date, number | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R092 | action, actor_name, reason | action: select; from: date; to: date | N/A — no status control | date, action | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R093 | batch_number, line_number | status: select; from: date; to: date | status: active, removed | date, amount, line | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R094 | event_type, reason, actor_name | event: select; status: select; from: date; to: date | status: draft, in_review, completed, superseded | date, event | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R095 | employee_number, full_name, preferred_name, work_email, work_phone, username, department_name, department_code, branch_name, branch_code, job_title, employment_status | department: select; branch: select; status: select | status: hr_employees.employment_status | name, number, department, status, hire_date | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R096 | employee_number, full_name, department_name, branch_name, job_title | period: select (daily/weekly/monthly); date: date; department: select; branch: select; status: select | status: present, late, absent, remote, on_leave, holiday | name, number, department, status, date | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R097 | employee_number, employee_name, preferred_name, department_name, leave_type_code, leave_type_name, request_status | from: date; to: date; status: select | status: pending, approved, rejected, cancelled | date, employee, number, policy, status, days | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R098 | display_name, employee_number, email, job_title, department_name, employment_status | month: month; status: select | status: hr_employees.employment_status | name, number, department, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R099 | display_name, employee_number, email, job_title, department_name, employment_status | month: month; status: select | status: hr_employees.employment_status | name, number, department, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R100 | code, name | employee: select context; year: select context | N/A — no status control | name, code, available, used, remaining | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R101 | leave_type_name, leave_type_code, reason, created_by_name | employee: select context; year: select context | N/A — no status control | date, policy, days | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R102 | code, name, country_code, subdivision_code, timezone | active: select (Active/Inactive); country: select; timezone: select | active: authorized full-dataset/domain source; see service | name, code, timezone, default | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R103 | name, description, calendar_name | calendar: select context; year: select context; type: select (workforce_holidays.holiday_type domain); portion: select; observed: select; from: date; to: date | N/A — no status control | date, name, type | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R104 | employee_number, employee_name, preferred_name, job_title, calendar_name | calendar: select context; year: select context | N/A — no status control | date, employee, number, calendar | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R105 | attendance_date, notes | month: month context; source: select; from: date; to: date; status: select | status: present, late, absent, remote, on_leave, holiday | date, status, minutes | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R106 | sku, product_name, changed_by_name, reason, status | from: date; to: date; status: select | status: submitted, approved, rejected | date, sku, product, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R107 | quotation_number, agent_name, manager_name, warehouse_name, warehouse_code, team_name, status, event_date, invoice_reference (history) | shop: select; from: date; to: date; status: select | status: submitted, allocated, reported, closed, sold, return_requested, returned, cancelled | date, reference, agent, manager, shop, status, priority (queue) | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R108 | quotation_number, agent_name, manager_name, warehouse_name, warehouse_code, team_name, status, event_date, invoice_reference (history) | shop: select; from: date; to: date; status: select | status: submitted, allocated, reported, closed, sold, return_requested, returned, cancelled | date, reference, agent, manager, shop, status, priority (queue) | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R109 | quotation_number, agent_name, manager_name, warehouse_name, warehouse_code, team_name, status, event_date, invoice_reference (history) | shop: select; from: date; to: date; status: select | status: submitted, allocated, reported, closed, sold, return_requested, returned, cancelled | date, reference, agent, manager, shop, status, priority (queue) | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R110 | quotation_number, agent_name, manager_name, warehouse_name, warehouse_code, team_name, status, event_date, invoice_reference (history) | shop: select; from: date; to: date; status: select | status: submitted, allocated, reported, closed, sold, return_requested, returned, cancelled | date, reference, agent, manager, shop, status, priority (queue) | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R111 | quotation_number, agent_name, manager_name, warehouse_name, warehouse_code, team_name, status, event_date, invoice_reference (history) | shop: select; from: date; to: date; status: select | status: submitted, allocated, reported, closed, sold, return_requested, returned, cancelled | date, reference, agent, manager, shop, status, priority (queue) | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R112 | employee.display_name, quotations.quotation_number, reports.invoice_reference, invoices.invoice_number, products.sku, products.name, shops.name, manager_search.display_name | period: select; date: date; view_by: select; product_id: select; employee_id: select; shop_id: select; from: date; to: date | N/A — no status control | amount, sold, returned, reports, currency, product, sku, employee, shop | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R113 | dsa_name, manager_name, external_reference, status | dsa_dsp_user_id: select; responsible_manager_id: select; date: date; safaricom_reference: text; from: date; to: date; status: select | status: submitted, approved, rejected, partially_settled, settled | date, dsa, manager, reference, status, amount | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R114 | settlement_number, bank_name, display_name, workflow_status, reconciliation_status, created_at | reconciliation: select (awaiting_confirmation/partial/matched/mismatch/review_required); from: date; to: date; status: select | reconciliation: authorized full-dataset/domain source; see service; status: draft, submitted, supervisor_reviewed, finance_reconciled, approved, closed, returned, cancelled | date, reference, bank, amount, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R115 | invoice_number, quotation_number, order_number, customer_name, invoice_reference, payment_reference, agent_name, manager_name | status: select (finance_invoices.status); payment: select (finance_invoices.payment_status); currency: select; from: date; to: date | status: authorized full-dataset/domain source; see service; payment: authorized full-dataset/domain source; see service | date, invoice, order, customer, agent, manager, status | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R116 | reference, document, currency | party_id: select context; currency: select; document: select; from: date; to: date | N/A — no status control | date, reference, document, currency, debit, credit | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R117 | reference, document, currency | party_id: select context; currency: select; document: select; from: date; to: date | N/A — no status control | date, reference, document, currency, debit, credit | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R118 | sku, product_name | ledger: select; warehouse_id: select context; location_id: select context; product_id: select context; date: date | N/A — no status control | sku, product, beginning, ending, reserved, available, difference | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R119 | sku, product_name, location_code, location_name, reference_number, notes | category: select; product: select; type: select; from: date; to: date; warehouse_id: select context; location_id: select context; product_id: select context; date: date | N/A — no status control | date, sku, product, location, category, quantity, reference | Yes / 25,50,100 / full filtered count | All filtered | All filtered |
| R120 | sku, product_name, reason, reference_number | type: select; warehouse_id: select context; location_id: select context; product_id: select context; date: date | N/A — no status control | date, sku, reason, reference | Yes / 25,50,100 / full filtered count | All filtered | All filtered |

## Not applicable surfaces

| Surface | Route / context | Concrete reason — NOT APPLICABLE |
|---|---|---|
| Trial Balance / Profit & Loss / Balance Sheet / Cash Flow | /finance/accounting/reports | Aggregate financial statements: page-level row slicing would break totals and accounting meaning; existing report semantics retained. |
| AR/AP/GL control reconciliation | /finance/reconciliation | Aggregate control-account/currency comparison, not a transaction register. Detailed movements are the ledger and statements in the matrix. |
| Dashboard / Finance KPI cards / organization setup / administration landing | /dashboard; /finance; /organization/setup; /administration | Finite summaries/navigation and current task counts. Finance receivable rows beneath KPIs ARE paged in the matrix. |
| Analytics / Power BI configuration | /analytics; /administration/analytics | Embedded external reports and integration settings; no local row register to paginate. |
| Direct asset creation / inventory capitalization | /assets-management?section=direct; section=capitalization | Workflow input/selection forms; preserve complete eligible options. Asset register and categories ARE converted. |
| Procurement receipt action | /procurement?section=receipts | Receiving workflow form and link to the shared Inventory receipt register. |
| Procurement payments | /procurement?section=payments | Payment action uses the supplier-bill register already in the matrix; no separate payment-history table exists here. |
| Quotation/order/picking/receipt/transfer/bank worksheet lines | Document detail/edit routes | Atomic transaction lines and allocation inputs must stay complete for review and posting. Independent events/payments/related documents ARE paged. |
| Quick Sale request/report/evidence/stock allocation inputs | /sales/quick-sale/{id} | One workflow transaction and its form inputs; independent event history IS converted. |
| Calendar seven weekday rules | /attendance/calendars | Fixed seven-day configuration form. Calendar catalogue, holidays and assignments ARE converted. |
| Roles edit-permissions / function access / module licensing / user overrides | /administration/access-control; /administration/roles/edit-permissions; /administration/modules; user edit | Atomic authorization configuration graph and selection inputs. Role catalogue and read-only member/permission registers ARE converted. |
| Account active sessions / assigned roles / effective grants | /account; /administration/users/view | Account security configuration, not a business register. Recent activity is an explicit preview with a full Activity-register link. |
| Single audit-event field difference | /administration/audit-logs/view | Atomic before/after inspection of one authorized event. Audit history register IS converted. |
| Import mapping / all-row validation / preview sample | /data-exchange/{entity}/import | Input schema/mapping and preview; every row is validated even when the preview shows a sample. Not a persisted business register. |
| API v1 Sales products/customers/orders/receivables/summary | /api/v1/sales/* | Integration JSON contracts, not HTML registers. Existing API authorization and response contracts preserved. |
| PDFs/evidence/authentication/health/notifications | PDF/evidence/login/password/account/health routes | One document/download, account operation or notification widget; no independent business register. |

## Navigation coverage

| Module | Navigation entry | Path | Classification |
|---|---|---|---|
| dashboard | Dashboard | /dashboard | Register matrix or explicit N/A above |
| analytics | Analytics | /analytics | Register matrix or explicit N/A above |
| hr | Human Resources | /hr | Register matrix or explicit N/A above |
| attendance | Attendance | /attendance | Register matrix or explicit N/A above |
| administration | Access Control | /administration/access-control | Register matrix or explicit N/A above |
| administration | Users | /administration/users | Register matrix or explicit N/A above |
| sales | Quick Sale | /sales/quick-sale | Register matrix or explicit N/A above |
| sales | DSA/DSP Sales Report | /sales/dsa-dsp-report | Register matrix or explicit N/A above |
| sales | Incentives | /sales/incentives | Register matrix or explicit N/A above |
| sales | Sales Orders | /sales/orders | Register matrix or explicit N/A above |
| sales | Quotations | /sales/quotations | Register matrix or explicit N/A above |
| sales | Customers | /sales/customers | Register matrix or explicit N/A above |
| sales | Products | /sales/products | Register matrix or explicit N/A above |
| sales | Selling Terms | /sales/pricing | Register matrix or explicit N/A above |
| sales | Mobile / MiFi Variants | /sales/product-variants | Register matrix or explicit N/A above |
| sales | Pricelists | /sales/pricelists | Register matrix or explicit N/A above |
| sales | DSA / DSP & Teams | /sales/teams | Register matrix or explicit N/A above |
| sales | Deliveries | /sales/deliveries | Register matrix or explicit N/A above |
| sales | Settlements | /sales/settlements | Register matrix or explicit N/A above |
| procurement | Overview | /procurement?section=overview | Register matrix or explicit N/A above |
| procurement | Requisitions | /procurement?section=requisitions | Register matrix or explicit N/A above |
| procurement | Purchase Orders | /procurement?section=orders | Register matrix or explicit N/A above |
| procurement | Suppliers | /procurement?section=suppliers | Register matrix or explicit N/A above |
| procurement | Receipts | /procurement?section=receipts | Register matrix or explicit N/A above |
| procurement | Supplier Bills | /procurement?section=bills | Register matrix or explicit N/A above |
| procurement | Payments | /procurement?section=payments | Register matrix or explicit N/A above |
| procurement | Returns | /procurement?section=returns | Register matrix or explicit N/A above |
| finance | Dashboard | /finance | Register matrix or explicit N/A above |
| finance | Receivables | /finance?section=receivables | Register matrix or explicit N/A above |
| finance | Customer Invoices | /finance/customer-invoices | Register matrix or explicit N/A above |
| finance | Receipts | /finance?section=receipts | Register matrix or explicit N/A above |
| finance | Sales Settlement Reconciliation | /finance/settlements | Register matrix or explicit N/A above |
| finance | Payables | /finance/accounting/payables | Register matrix or explicit N/A above |
| finance | Expenses | /finance/expenses | Register matrix or explicit N/A above |
| finance | Expense History | /finance?section=expenses | Register matrix or explicit N/A above |
| finance | Staff Loans & Advances | /finance/staff-loans | Register matrix or explicit N/A above |
| finance | Cash & Bank | /finance/accounting/cash-bank | Register matrix or explicit N/A above |
| finance | Bank Reconciliation | /finance/bank-reconciliation | Register matrix or explicit N/A above |
| finance | Chart of Accounts | /finance/accounting/accounts | Register matrix or explicit N/A above |
| finance | Journals | /finance?section=journals | Register matrix or explicit N/A above |
| finance | General Ledger | /finance/accounting/ledger | Register matrix or explicit N/A above |
| finance | Accounting Periods | /finance/accounting-periods | Register matrix or explicit N/A above |
| finance | Reports | /finance/accounting/reports | Register matrix or explicit N/A above |
| inventory | Current Stock | /inventory?section=stock | Register matrix or explicit N/A above |
| inventory | Stock Requests | /inventory/stock-requests | Register matrix or explicit N/A above |
| inventory | Daily Stock History | /inventory/stock-daily-history | Register matrix or explicit N/A above |
| inventory | Movements | /inventory?section=movements | Register matrix or explicit N/A above |
| inventory | Receipts | /inventory/receipts | Register matrix or explicit N/A above |
| inventory | Transfers | /inventory/transfers | Register matrix or explicit N/A above |
| inventory | Warehouses | /inventory/warehouses | Register matrix or explicit N/A above |
| inventory | Locations | /inventory/locations | Register matrix or explicit N/A above |
| assets | Asset Register | /assets-management?section=register | Register matrix or explicit N/A above |
| assets | Direct Assets | /assets-management?section=direct | Register matrix or explicit N/A above |
| assets | Asset Categories | /assets-management?section=categories | Register matrix or explicit N/A above |
| assets | Capitalization | /assets-management?section=capitalization | Register matrix or explicit N/A above |

## All registered GET routes

The register matrix includes query-section routes and child tables that are not separate route registrations. Create/edit/action/download routes retain their existing business purpose.

| GET route | Actual registered handler |
|---|---|
| /api/v1/sales/products | ApiV1SalesController::products |
| /api/v1/sales/products/{id} | ApiV1SalesController::product |
| /api/v1/sales/customers | ApiV1SalesController::customers |
| /api/v1/sales/customers/{id} | ApiV1SalesController::customer |
| /api/v1/sales/orders | ApiV1SalesController::orders |
| /api/v1/sales/orders/{id} | ApiV1SalesController::order |
| /api/v1/sales/receivables | ApiV1SalesController::receivables |
| /api/v1/sales/receivables/{id} | ApiV1SalesController::receivable |
| /api/v1/sales/reports/summary | ApiV1SalesController::reportSummary |
| /administration/access-control | AccessControlController::index |
| /account | HomeController::account |
| /administration/users | UserAdministrationController::index |
| /hr | HrController::index |
| /hr/leave | LeaveController::index |
| /hr/team | ManagerWorkspaceController::index |
| /hr/leave/balances | LeaveBalanceController::index |
| /hr/leave/policies | LeavePolicyController::index |
| /hr/leave/policies/create | LeavePolicyController::create |
| /hr/leave/policies/edit | LeavePolicyController::edit |
| /attendance | AttendanceController::index |
| /attendance/calendars | WorkforceCalendarController::index |
| /attendance/me | AttendanceSelfServiceController::index |
| /attendance/team | AttendanceSelfServiceController::team |
| /finance | FinanceController::index |
| /finance/customer-invoices | FinanceController::customerInvoices |
| /finance/customer-invoices/{id} | FinanceController::customerInvoice |
| /inventory | InventoryController::index |
| /inventory/stock-requests | StockRequestController::index |
| /inventory/stock-daily-history | SalesStockHistoryController::index |
| /inventory/stock-requests/{id} | StockRequestController::show |
| /procurement | ProcurementController::index |
| /procurement/{id} | ProcurementController::showOrder |
| /inventory/transfers | InventoryController::transfers |
| /inventory/transfers/{id} | InventoryController::showTransfer |
| /inventory/receipts | InventoryController::receipts |
| /inventory/receipts/create | InventoryController::createReceipt |
| /assets-management | AssetController::index |
| /assets-management/{id} | AssetController::show |
| /inventory/receipts/{id} | InventoryController::showReceipt |
| /inventory/warehouses | WarehouseController::index |
| /inventory/warehouses/create | WarehouseController::create |
| /inventory/locations | WarehouseLocationController::index |
| /inventory/locations/create | WarehouseLocationController::create |
| /sales | SalesController::index |
| /sales/settlements | SalesSettlementController::index |
| /sales/settlements/{id} | SalesSettlementController::show |
| /sales/settlements/{id}/confirmations/{confirmationId}/evidence | SalesSettlementController::evidence |
| /sales/settlements/{id}/deposit-advice.pdf | SalesSettlementController::depositAdvice |
| /sales/settlements/{id}/reconciliation.pdf | SalesSettlementController::reconciliation |
| /finance/settlements | SalesSettlementController::finance |
| /finance/settlements/{id} | SalesSettlementController::show |
| /finance/settlements/{id}/confirmations/{confirmationId}/evidence | SalesSettlementController::evidence |
| /finance/settlements/{id}/deposit-advice.pdf | SalesSettlementController::depositAdvice |
| /finance/settlements/{id}/reconciliation.pdf | SalesSettlementController::reconciliation |
| /sales/quotations/{id}/proforma.pdf | CommercialDocumentController::proforma |
| /finance/customer-invoices/{id}/invoice.pdf | CommercialDocumentController::invoice |
| /finance/payments/{id}/receipt.pdf | CommercialDocumentController::receipt |
| /data-exchange/{entity}/import | DataExchangeController::show |
| /data-exchange/{entity}/template | DataExchangeController::template |
| /data-exchange/{entity}/export/configure | DataExchangeController::exportForm |
| /data-exchange/{entity}/export | DataExchangeController::export |
| /sales/customers | SalesController::customers |
| /sales/products | SalesController::products |
| /sales/customers/{id} | SalesController::showCustomer |
| /sales/products/{id} | SalesController::showProduct |
| /sales/quotations | SalesController::quotations |
| /sales/quick-sale | SalesController::quickSale |
| /sales/dsa-dsp-report | SalesReportController::index |
| /sales/pricing | SalesPricingController::index |
| /sales/product-variants | SalesProductVariantController::index |
| /sales/incentives | SalesIncentiveController::index |
| /sales/incentives/{id} | SalesIncentiveController::show |
| /sales/quick-sale/{id}/reports/{reportId}/evidence | SalesController::quickSaleEvidence |
| /sales/quick-sale/{id} | SalesController::showQuickSale |
| /sales/quotations/create | SalesController::createQuotation |
| /sales/quotations/{id}/edit | SalesController::editQuotation |
| /sales/quotations/{id} | SalesController::showQuotation |
| /sales/orders | SalesController::orders |
| /sales/orders/{id} | SalesController::showOrder |
| /sales/pricelists | SalesController::pricelists |
| /sales/teams | SalesController::teams |
| /sales/pricelists/{id} | SalesController::showPricelist |
| /sales/teams/{id} | SalesController::showTeam |
| /sales/deliveries | SalesController::deliveries |
| /sales/deliveries/{id} | SalesController::showDelivery |
| /sales/export | SalesController::export |
| /organization/setup | OrganizationSetupController::index |
| /organization/branches | BranchController::index |
| /organization/branches/create | BranchController::create |
| /organization/branches/edit | BranchController::edit |
| /organization/job-titles | JobTitleController::index |
| /organization/job-titles/create | JobTitleController::create |
| /organization/job-titles/edit | JobTitleController::edit |
| /organization/departments | DepartmentController::index |
| /organization/departments/create | DepartmentController::create |
| /organization/departments/edit | DepartmentController::edit |
| /organization/positions | PositionController::index |
| /organization/positions/create | PositionController::create |
| /organization/positions/edit | PositionController::edit |
| /hr/employees/view | HrController::show |
| /hr/employees/activity | EmployeeActivityController::index |
| /hr/employees/position | EmployeePositionController::edit |
| /hr/employees/create | HrController::createEmployee |
| /hr/employees/edit | HrController::editEmployee |
| /hr/departments | HrController::departments |
| /hr/departments/create | HrController::createDepartment |
| /hr/departments/edit | HrController::editDepartment |
| /administration/audit-logs | AuditLogController::index |
| /administration/modules | ModuleAdministrationController::index |
| /administration/companies | CompanyAdministrationController::index |
| /administration/companies/create | CompanyAdministrationController::create |
| /administration/companies/view | CompanyAdministrationController::show |
| /administration/companies/edit | CompanyAdministrationController::edit |
| /administration/companies/reset-owner-password | CompanyAdministrationController::showOwnerPasswordReset |
| /administration/companies/reset-user-password | CompanyAdministrationController::showCompanyUserPasswordReset |
| /administration/audit-logs/view | AuditLogController::show |
| /administration/users/activity | UserActivityController::index |
| /administration/roles | RoleAdministrationController::index |
| /administration/roles/view | RoleAdministrationController::show |
| /administration/roles/edit-permissions | RoleAdministrationController::editPermissions |
| / | HomeController::index |
| /health | HomeController::health |
| /login | AuthController::showLogin |
| /change-password | AuthController::showChangePassword |
| /dashboard | DashboardController::index |
| /analytics | PowerBiController::index |
| /administration/integration-events | IntegrationEventController::index |
| /finance/accounting-periods | FinanceController::accountingPeriods |
| /finance/accounting/{section} | FinanceAccountingController::show |
| /finance/statements/{kind} | FinanceAccountingController::statement |
| /finance/reconciliation | FinanceAccountingController::reconciliation |
| /finance/bank-reconciliation | FinanceBankReconciliationController::index |
| /finance/bank-reconciliation/{id} | FinanceBankReconciliationController::show |
| /finance/expenses | FinanceExpenseController::index |
| /finance/expenses/{id}/evidence/{evidenceId} | FinanceExpenseController::evidence |
| /finance/staff-loans | FinanceStaffLoanController::index |
| /finance/staff-loans/{id} | FinanceStaffLoanController::detail |
| /administration/analytics | PowerBiController::configuration |
| /administration | AdministrationController::index |
| /administration/users/create | UserAdministrationController::create |
| /administration/users/view | UserAdministrationController::show |
| /administration/users/edit | UserAdministrationController::edit |
| /administration/users/inventory-access | UserAdministrationController::inventoryAccess |
| /administration/users/reset-password | UserAdministrationController::showResetPassword |
| /administration/users/account-status | UserAdministrationController::showAccountStatus |
| /administration/users/unlock | UserAdministrationController::showUnlockAccount |
| /procurement/requisitions/{id} | ProcurementController::showRequisition |

## Remaining source caps and client-side code

The residual fixed caps are in legacy null-input helpers (AccountingPeriodService, FinanceAccountingWorkspaceService, FinanceExpenseService, FinanceStaffLoanService, InventoryService, ProcurementService, QuickSaleRouting, SalesQuickSaleService), old LeaveRepository catalog/request helpers, PositionRepository catalogue and ManagerTeamRepository/LeaveBalanceRepository legacy reads. Converted HTTP controllers pass shared-list input or use new list factories; these legacy caps do not supply the converted register/export rows. Document detail calls skip the old capped histories. Workflow selectors for managers, users, branches, job titles, positions, warehouses and locations retain full authorized sources. Active leave-type choices already have no cap.

Sales-pricing browser row filtering was removed. The remaining app.js table scan adds Action Required badges; it does not remove rows or determine counts. Form option selection, atomic workflow editing, field calculations and detail toggles remain client-side by design. Shared paginator duplication at top/bottom is intentional; each list has one filter form and one pair of export links. Categorical controls receive populated authoritative options or full authorized DISTINCT values. An empty data-driven source may correctly have only All; finite state domains stay selectable even when a register has zero records.

## Query-plan limits

Read-only EXPLAIN probes on the isolated fixture resolve company indexes for Sales orders, Finance journal entries and Procurement orders. Small-fixture plans include filesort/temporary operations and dependent subqueries. The Inventory probe had an impossible scope (fixture actor has no operational warehouse) and is not performance evidence. Populated Inventory scope tests supply the behavior evidence. No production performance benchmark or production index migration was performed.
