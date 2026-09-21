# Registered-route permission inventory 094

Generated from every registered route and reflected controller/helper source. This is a static inventory, not a claim that every workflow and data query has passed end-to-end review. A = explicit function grant found; B = broad grant remains and needs semantic review (the final column records added landing gates); C = role check; D = navigation-only; E = no literal permission resolved, manual review required; F = authenticated account/system route; G = public/auth route. Indirect service authorization and dynamic permission suffixes require manual review.

Routes: 351. Classification totals: {"G":8,"A":260,"F":8,"B":75}.

| Method | Route | Handler | Class | Controller/helper permission evidence | Shared landing gate |
|---|---|---|---|---|---|
| POST | /api/v1/oauth/token | ApiV1SalesController::token | G |  | Controller contract |
| GET | /api/v1/sales/products | ApiV1SalesController::products | A | sales.products.read | Controller contract |
| GET | /api/v1/sales/products/{id} | ApiV1SalesController::product | A | sales.products.read | Controller contract |
| GET | /api/v1/sales/customers | ApiV1SalesController::customers | A | sales.customers.read | Controller contract |
| POST | /api/v1/sales/customers | ApiV1SalesController::createCustomer | A | sales.customers.write | Controller contract |
| GET | /api/v1/sales/customers/{id} | ApiV1SalesController::customer | A | sales.customers.read | Controller contract |
| GET | /api/v1/sales/orders | ApiV1SalesController::orders | A | sales.orders.read | Controller contract |
| POST | /api/v1/sales/orders | ApiV1SalesController::createOrder | A | sales.orders.write | Controller contract |
| GET | /api/v1/sales/orders/{id} | ApiV1SalesController::order | A | sales.orders.read | Controller contract |
| POST | /api/v1/sales/orders/{id}/submit | ApiV1SalesController::submitOrder | A | sales.orders.submit | Controller contract |
| POST | /api/v1/sales/orders/{id}/cancel | ApiV1SalesController::cancelOrder | A | sales.orders.cancel | Controller contract |
| POST | /api/v1/sales/orders/{id}/payments | ApiV1SalesController::payment | A | sales.payments.write | Controller contract |
| GET | /api/v1/sales/receivables | ApiV1SalesController::receivables | A | sales.receivables.read | Controller contract |
| GET | /api/v1/sales/receivables/{id} | ApiV1SalesController::receivable | A | sales.receivables.read | Controller contract |
| GET | /api/v1/sales/reports/summary | ApiV1SalesController::reportSummary | A | sales.reports.read | Controller contract |
| GET | /administration/access-control | AccessControlController::index | A | administration.roles.manage | administration.roles.manage |
| POST | /administration/access-control | AccessControlController::save | A | administration.roles.manage | administration.roles.manage |
| GET | /account | HomeController::account | F |  | Controller contract |
| GET | /administration/users | UserAdministrationController::index | A | administration.users.index, administration.users.manage | administration.users.manage |
| GET | /hr | HrController::index | B | attendance.records.manage, attendance.records.view, attendance.self.record, attendance.self.view, attendance.team.view, hr.index, hr.leave.approve, hr.leave.balance.manage, hr.leave.manage, hr.leave.policy.manage, hr.leave.self.request, hr.leave.self.view, hr.leave.team.approve, hr.leave.view, hr.records.manage, hr.records.view, organization.branches.manage, organization.branches.view, organization.departments.manage, organization.departments.view, organization.job_titles.manage, organization.job_titles.view, organization.positions.manage, organization.positions.view | hr.records.view OR hr.records.manage OR hr.leave.view OR hr.leave.manage OR hr.leave.approve OR hr.leave.self.view OR hr.leave.self.request OR hr.leave.team.approve OR hr.leave.policy.manage OR hr.leave.balance.manage |
| GET | /hr/leave | LeaveController::index | A | hr.leave.approve, hr.leave.balance.manage, hr.leave.index, hr.leave.manage, hr.leave.policy.manage, hr.leave.self.request, hr.leave.self.view, hr.leave.team.approve, hr.leave.view | Controller contract |
| GET | /hr/team | ManagerWorkspaceController::index | B | hr.leave.approve, hr.leave.manage, hr.leave.self.request, hr.leave.self.view, hr.leave.team.approve, hr.leave.view, hr.records.manage, hr.records.view, hr.team.index | Controller contract |
| POST | /hr/leave | LeaveController::store | A | hr.leave.manage, hr.leave.self.request | Controller contract |
| POST | /hr/leave/decision | LeaveController::decide | A | hr.leave.approve, hr.leave.team.approve | Controller contract |
| GET | /hr/leave/balances | LeaveBalanceController::index | A | hr.leave.balance.manage, hr.leave.balances.index | Controller contract |
| POST | /hr/leave/balances/allocation | LeaveBalanceController::saveAllocation | A | hr.leave.balance.manage | Controller contract |
| POST | /hr/leave/balances/adjustment | LeaveBalanceController::addAdjustment | A | hr.leave.balance.manage | Controller contract |
| GET | /hr/leave/policies | LeavePolicyController::index | A | hr.leave.policies.index, hr.leave.policy.manage | Controller contract |
| GET | /hr/leave/policies/create | LeavePolicyController::create | A | hr.leave.policies.form, hr.leave.policy.manage | Controller contract |
| POST | /hr/leave/policies | LeavePolicyController::store | A | hr.leave.policy.manage | Controller contract |
| GET | /hr/leave/policies/edit | LeavePolicyController::edit | A | hr.leave.policies.form, hr.leave.policy.manage | Controller contract |
| POST | /hr/leave/policies/update | LeavePolicyController::update | A | hr.leave.policy.manage | Controller contract |
| GET | /attendance | AttendanceController::index | A | attendance.index, attendance.records.manage, attendance.records.view, attendance.self.view, attendance.team.view | attendance.records.view OR attendance.records.manage |
| GET | /attendance/calendars | WorkforceCalendarController::index | A | attendance.calendars.index, attendance.records.manage | Controller contract |
| POST | /attendance/calendars | WorkforceCalendarController::storeCalendar | A | attendance.records.manage | Controller contract |
| POST | /attendance/calendars/week | WorkforceCalendarController::saveWeek | A | attendance.records.manage | Controller contract |
| POST | /attendance/calendars/holidays | WorkforceCalendarController::storeHoliday | A | attendance.records.manage | Controller contract |
| POST | /attendance/calendars/schedules | WorkforceCalendarController::assignSchedule | A | attendance.records.manage | Controller contract |
| POST | /attendance/records | AttendanceController::store | A | attendance.records.manage | Controller contract |
| GET | /attendance/me | AttendanceSelfServiceController::index | A | attendance.self.index, attendance.self.record, attendance.self.view, attendance.team.view | Controller contract |
| POST | /attendance/me/check-in | AttendanceSelfServiceController::checkIn | A | attendance.self.record | Controller contract |
| POST | /attendance/me/check-out | AttendanceSelfServiceController::checkOut | A | attendance.self.record | Controller contract |
| POST | /attendance/me/scan | AttendanceSelfServiceController::scan | A | attendance.self.record | Controller contract |
| POST | /attendance/me/reminders | AttendanceSelfServiceController::saveReminders | A | attendance.self.view | Controller contract |
| POST | /attendance/me/notifications/read | AttendanceSelfServiceController::markNotificationRead | A | attendance.self.view | Controller contract |
| POST | /attendance/me/push/subscribe | AttendanceSelfServiceController::subscribePush | A | attendance.self.view | Controller contract |
| POST | /attendance/me/push/unsubscribe | AttendanceSelfServiceController::unsubscribePush | A | attendance.self.view | Controller contract |
| GET | /attendance/team | AttendanceSelfServiceController::team | A | attendance.team.index, attendance.team.view | Controller contract |
| GET | /finance | FinanceController::index | B | finance.index, finance.records.manage, finance.records.view, finance.requests.approve | finance.dashboard.view |
| GET | /finance/customer-invoices | FinanceController::customerInvoices | B | finance.records.view | finance.invoices.view |
| GET | /finance/customer-invoices/{id} | FinanceController::customerInvoice | B | finance.records.manage, finance.records.view | finance.invoices.view |
| POST | /finance/customer-invoices/{id}/post | FinanceController::postCustomerInvoice | B | finance.records.manage | finance.invoices.view |
| POST | /finance/customer-invoices/{id}/payments | FinanceController::registerCustomerPayment | B | finance.records.manage | finance.invoices.view |
| GET | /inventory | InventoryController::index | A | inventory.index, inventory.stock.view | inventory.stock.view |
| GET | /inventory/stock-requests | StockRequestController::index | A | inventory.stock_requests.create, inventory.stock_requests.process, inventory.stock_requests.view | inventory.stock_requests.view |
| GET | /inventory/stock-daily-history | SalesStockHistoryController::index | A | inventory.stock.view | inventory.history.view |
| GET | /inventory/stock-requests/{id} | StockRequestController::show | A | inventory.stock_requests.create, inventory.stock_requests.process, inventory.stock_requests.view | inventory.stock_requests.view |
| POST | /inventory/stock-requests | StockRequestController::create | A | inventory.stock_requests.create | inventory.stock_requests.view |
| POST | /inventory/stock-requests/{id}/peer-proposals | StockRequestController::proposePeer | A | inventory.stock_requests.process | inventory.stock_requests.view |
| POST | /inventory/peer-proposals/{id}/decision | StockRequestController::decidePeer | A | inventory.stock_requests.process | Controller contract |
| POST | /inventory/stock-requests/{id}/process | StockRequestController::process | A | inventory.stock_requests.process | inventory.stock_requests.view |
| POST | /inventory/stock-requests/{id}/reject | StockRequestController::reject | A | inventory.stock_requests.process | inventory.stock_requests.view |
| POST | /inventory/stock-requests/{id}/resubmit | StockRequestController::resubmit | A | inventory.stock_requests.create | inventory.stock_requests.view |
| POST | /inventory/stock-requests/{id}/issue | StockRequestController::issue | A | inventory.stock_requests.issue | inventory.stock_requests.view |
| POST | /inventory/stock-requests/{id}/receive | StockRequestController::receive | A | inventory.stock_requests.receive | inventory.stock_requests.view |
| POST | /inventory/stock-requests/authorities | StockRequestController::saveAuthority | A | inventory.stock_authorities.manage | inventory.stock_requests.view |
| POST | /inventory/stock-requests/reorder-thresholds | StockRequestController::saveReorderThreshold | A | inventory.reorder_thresholds.manage | inventory.stock_requests.view |
| GET | /procurement | ProcurementController::index | B | procurement.index, procurement.view | procurement.overview.view |
| GET | /procurement/{id} | ProcurementController::showOrder | B | procurement.index, procurement.view | Controller contract |
| POST | /procurement/suppliers | ProcurementController::supplier | A | procurement.suppliers.manage | procurement.suppliers.view |
| POST | /procurement/suppliers/{id} | ProcurementController::updateSupplier | A | procurement.suppliers.manage | procurement.suppliers.view |
| POST | /procurement/suppliers/{id}/active | ProcurementController::supplierActive | A | procurement.suppliers.manage | procurement.suppliers.view |
| POST | /procurement/requisitions | ProcurementController::requisition | A | procurement.requisitions.create | procurement.requisitions.view |
| POST | /procurement/requisitions/{id}/action | ProcurementController::requisitionAction | A | procurement.requisitions.approve, procurement.requisitions.create | procurement.requisitions.view |
| POST | /procurement/orders | ProcurementController::order | A | procurement.orders.create | procurement.orders.view |
| POST | /procurement/{id}/action | ProcurementController::orderAction | A | procurement.orders.approve, procurement.orders.confirm, procurement.orders.create | Controller contract |
| POST | /procurement/{id}/receipts | ProcurementController::receipt | A | procurement.receipts.create | Controller contract |
| POST | /procurement/{id}/bills | ProcurementController::bill | A | procurement.bills.create | Controller contract |
| POST | /procurement/bills/{id}/post | ProcurementController::postBill | A | procurement.bills.post | procurement.bills.view |
| POST | /procurement/bills/{id}/payments | ProcurementController::payBill | A | procurement.payments.post | procurement.bills.view |
| POST | /procurement/bills/{id}/reverse | ProcurementController::reverseBill | A | procurement.bills.reverse | procurement.bills.view |
| POST | /procurement/{id}/returns | ProcurementController::vendorReturn | A | procurement.returns.post | Controller contract |
| GET | /inventory/transfers | InventoryController::transfers | A | inventory.transfers, inventory.transfers.view | inventory.transfers.view |
| POST | /inventory/transfers | InventoryController::createTransfer | A | inventory.transfers.create | inventory.transfers.view |
| GET | /inventory/transfers/{id} | InventoryController::showTransfer | A | inventory.transfers, inventory.transfers.view | inventory.transfers.view |
| POST | /inventory/transfers/{id}/action | InventoryController::transferAction | A | inventory.transfers.approve, inventory.transfers.create | inventory.transfers.view |
| POST | /inventory/transfers/{id}/dispatch | InventoryController::dispatchTransfer | A | inventory.transfers.dispatch | inventory.transfers.view |
| POST | /inventory/transfers/{id}/receive | InventoryController::receiveTransfer | A | inventory.transfers.receive | inventory.transfers.view |
| GET | /inventory/receipts | InventoryController::receipts | A | inventory.receipts, inventory.receipts.approve, inventory.receipts.create, inventory.receipts.post, inventory.receipts.view | inventory.receipts.view |
| GET | /inventory/receipts/create | InventoryController::createReceipt | A | inventory.receipts, inventory.receipts.approve, inventory.receipts.create, inventory.receipts.post | inventory.receipts.view |
| POST | /inventory/receipts | InventoryController::storeReceipt | A | inventory.receipts.create | inventory.receipts.view |
| GET | /assets-management | AssetController::index | A | assets.index, assets.view | assets.view |
| POST | /assets-management/categories | AssetController::storeCategory | B | assets.manage | assets.categories.manage |
| POST | /assets-management | AssetController::storeAsset | B | assets.manage | assets.view |
| POST | /assets-management/capitalize | AssetController::capitalize | A | assets.inventory.capitalize | Controller contract |
| POST | /assets-management/{id}/activate | AssetController::activate | A | assets.activate | Controller contract |
| POST | /assets-management/{id}/depreciation/{lineId}/post | AssetController::postDepreciation | A | assets.depreciation.post | Controller contract |
| POST | /assets-management/{id}/transfer | AssetController::transfer | B | assets.manage | Controller contract |
| POST | /assets-management/{id}/maintenance | AssetController::maintenance | B | assets.manage | Controller contract |
| POST | /assets-management/{id}/tracking | AssetController::tracking | B | assets.manage | Controller contract |
| POST | /assets-management/{id}/dispose | AssetController::dispose | A | assets.dispose | Controller contract |
| GET | /assets-management/{id} | AssetController::show | A | assets.show, assets.view | Controller contract |
| GET | /inventory/receipts/{id} | InventoryController::showReceipt | A | inventory.receipts, inventory.receipts.approve, inventory.receipts.create, inventory.receipts.post, inventory.receipts.view | inventory.receipts.view |
| POST | /inventory/receipts/{id}/approve | InventoryController::approveReceipt | A | inventory.receipts.approve | inventory.receipts.view |
| POST | /inventory/receipts/{id}/post | InventoryController::postReceipt | A | inventory.receipts.post | inventory.receipts.view |
| GET | /inventory/warehouses | WarehouseController::index | A | inventory.warehouses.index, inventory.warehouses.manage, inventory.warehouses.view | inventory.warehouses.view |
| GET | /inventory/warehouses/create | WarehouseController::create | A | inventory.warehouses.form, inventory.warehouses.manage | inventory.warehouses.view |
| POST | /inventory/warehouses | WarehouseController::store | A | inventory.warehouses.manage | inventory.warehouses.view |
| GET | /inventory/locations | WarehouseLocationController::index | A | inventory.locations.index, inventory.warehouses.manage, inventory.warehouses.view | inventory.locations.view |
| GET | /inventory/locations/create | WarehouseLocationController::create | A | inventory.locations.form, inventory.warehouses.manage | inventory.locations.view |
| POST | /inventory/locations | WarehouseLocationController::store | A | inventory.warehouses.manage | inventory.locations.view |
| POST | /inventory/locations/provision | WarehouseLocationController::provision | A | inventory.warehouses.manage | inventory.locations.view |
| GET | /sales | SalesController::index | B | sales.catalogue.manage, sales.commissions.manage, sales.index, sales.orders.approve, sales.orders.cancel, sales.orders.confirm, sales.orders.create, sales.orders.submit, sales.payments.record, sales.pricing.manage, sales.pricing.view, sales.reports.export, sales.serials.manage, sales.targets.manage, sales.view | sales.orders.view |
| GET | /sales/settlements | SalesSettlementController::index | A | sales.settlements, sales.settlements.view | sales.settlements.view |
| POST | /sales/settlements | SalesSettlementController::create | A | sales.settlements.create | sales.settlements.view |
| GET | /sales/settlements/{id} | SalesSettlementController::show | A | finance.settlements.view, sales.settlement, sales.settlements.view | sales.settlements.view |
| POST | /sales/settlements/{id}/submit | SalesSettlementController::submit | A | sales.settlements.submit | sales.settlements.view |
| POST | /sales/settlements/{id}/review | SalesSettlementController::review | A | sales.settlements.review | sales.settlements.view |
| POST | /sales/settlements/{id}/reconcile | SalesSettlementController::reconcile | A | finance.settlements.reconcile | sales.settlements.view |
| POST | /sales/settlements/{id}/approve | SalesSettlementController::approve | A | finance.settlements.approve | sales.settlements.view |
| POST | /sales/settlements/{id}/confirmations | SalesSettlementController::confirmation | A | finance.bank_confirmations.create | sales.settlements.view |
| GET | /sales/settlements/{id}/confirmations/{confirmationId}/evidence | SalesSettlementController::evidence | A | finance.settlements.view, sales.settlements.view | sales.settlements.view |
| GET | /sales/settlements/{id}/deposit-advice.pdf | SalesSettlementController::depositAdvice | A | finance.settlements.view, sales.settlements.view | sales.settlements.view |
| GET | /sales/settlements/{id}/reconciliation.pdf | SalesSettlementController::reconciliation | A | finance.settlements.view, sales.settlements.view | sales.settlements.view |
| GET | /finance/settlements | SalesSettlementController::finance | A | finance.settlements.view, sales.settlements | finance.settlements.view |
| POST | /finance/settlements | SalesSettlementController::create | A | sales.settlements.create | finance.settlements.view |
| GET | /finance/settlements/{id} | SalesSettlementController::show | A | finance.settlements.view, sales.settlement, sales.settlements.view | finance.settlements.view |
| POST | /finance/settlements/{id}/submit | SalesSettlementController::submit | A | sales.settlements.submit | finance.settlements.view |
| POST | /finance/settlements/{id}/review | SalesSettlementController::review | A | sales.settlements.review | finance.settlements.view |
| POST | /finance/settlements/{id}/reconcile | SalesSettlementController::reconcile | A | finance.settlements.reconcile | finance.settlements.view |
| POST | /finance/settlements/{id}/approve | SalesSettlementController::approve | A | finance.settlements.approve | finance.settlements.view |
| POST | /finance/settlements/{id}/confirmations | SalesSettlementController::confirmation | A | finance.bank_confirmations.create | finance.settlements.view |
| GET | /finance/settlements/{id}/confirmations/{confirmationId}/evidence | SalesSettlementController::evidence | A | finance.settlements.view, sales.settlements.view | finance.settlements.view |
| GET | /finance/settlements/{id}/deposit-advice.pdf | SalesSettlementController::depositAdvice | A | finance.settlements.view, sales.settlements.view | finance.settlements.view |
| GET | /finance/settlements/{id}/reconciliation.pdf | SalesSettlementController::reconciliation | A | finance.settlements.view, sales.settlements.view | finance.settlements.view |
| POST | /finance/company-bank-accounts | SalesSettlementController::bankAccount | A | finance.bank_accounts.manage | Controller contract |
| GET | /sales/quotations/{id}/proforma.pdf | CommercialDocumentController::proforma | B | commercial_documents.download, finance.records.view, sales.orders.view | sales.quotations.view |
| GET | /finance/customer-invoices/{id}/invoice.pdf | CommercialDocumentController::invoice | B | commercial_documents.download, finance.records.view, sales.orders.view | finance.invoices.view |
| GET | /finance/payments/{id}/receipt.pdf | CommercialDocumentController::receipt | B | commercial_documents.download, finance.records.view, sales.orders.view | Controller contract |
| GET | /data-exchange/{entity}/import | DataExchangeController::show | B | finance.records.view, procurement.suppliers.manage, procurement.view | Controller contract |
| POST | /data-exchange/{entity}/preview | DataExchangeController::preview | B | finance.records.view, procurement.suppliers.manage, procurement.view | Controller contract |
| POST | /data-exchange/{entity}/import | DataExchangeController::execute | B | finance.records.view, procurement.suppliers.manage, procurement.view | Controller contract |
| GET | /data-exchange/{entity}/template | DataExchangeController::template | B | finance.records.view, procurement.suppliers.manage, procurement.view | Controller contract |
| GET | /data-exchange/{entity}/export/configure | DataExchangeController::exportForm | B | finance.records.view, procurement.suppliers.manage, procurement.view | Controller contract |
| GET | /data-exchange/{entity}/export | DataExchangeController::export | B | finance.records.view, procurement.suppliers.manage, procurement.view | Controller contract |
| GET | /sales/customers | SalesController::customers | B | sales.catalogue.manage, sales.commissions.manage, sales.index, sales.orders.approve, sales.orders.cancel, sales.orders.confirm, sales.orders.create, sales.orders.submit, sales.payments.record, sales.pricing.manage, sales.pricing.view, sales.reports.export, sales.serials.manage, sales.targets.manage, sales.view | sales.customers.view |
| GET | /sales/products | SalesController::products | B | sales.catalogue.manage, sales.commissions.manage, sales.index, sales.orders.approve, sales.orders.cancel, sales.orders.confirm, sales.orders.create, sales.orders.submit, sales.payments.record, sales.pricing.manage, sales.pricing.view, sales.reports.export, sales.serials.manage, sales.targets.manage, sales.view | sales.products.view |
| GET | /sales/customers/{id} | SalesController::showCustomer | B | sales.catalogue.manage, sales.master, sales.view | sales.customers.view |
| GET | /sales/products/{id} | SalesController::showProduct | B | sales.catalogue.manage, sales.master, sales.view | sales.products.view |
| GET | /sales/quotations | SalesController::quotations | B | sales.catalogue.manage, sales.commissions.manage, sales.index, sales.orders.approve, sales.orders.cancel, sales.orders.confirm, sales.orders.create, sales.orders.submit, sales.payments.record, sales.pricing.manage, sales.pricing.view, sales.reports.export, sales.serials.manage, sales.targets.manage, sales.view | sales.quotations.view |
| GET | /sales/quick-sale | SalesController::quickSale | A | sales.quick_sale.review, sales.quick_sale.use | sales.quick_sale.use OR sales.quick_sale.review |
| GET | /sales/dsa-dsp-report | SalesReportController::index | A | sales.report.review, sales.report.submit | sales.report.submit OR sales.report.review |
| GET | /sales/pricing | SalesPricingController::index | A | sales.pricing, sales.pricing.manage, sales.pricing.view | sales.pricing.view |
| POST | /sales/pricing | SalesPricingController::submit | A | sales.pricing.manage | sales.pricing.view |
| GET | /sales/product-variants | SalesProductVariantController::index | A | sales.catalogue.manage | sales.catalogue.manage |
| POST | /sales/product-variants/brands | SalesProductVariantController::brand | A | sales.catalogue.manage | sales.catalogue.manage |
| POST | /sales/product-variants/models | SalesProductVariantController::model | A | sales.catalogue.manage | sales.catalogue.manage |
| POST | /sales/product-variants/assign | SalesProductVariantController::assign | A | sales.catalogue.manage | sales.catalogue.manage |
| GET | /sales/incentives | SalesIncentiveController::index | A | sales.incentive.approve, sales.incentive.settle, sales.incentive.submit, sales.incentives, sales.incentive.view | sales.incentive.view |
| GET | /sales/incentives/{id} | SalesIncentiveController::show | A | sales.incentive.approve, sales.incentive.settle, sales.incentive.submit, sales.incentive.view | sales.incentive.view |
| POST | /sales/incentives/floats | SalesIncentiveController::issueFloat | A | sales.incentive.approve, sales.incentive.view | sales.incentive.view |
| POST | /sales/incentives/claims | SalesIncentiveController::submit | A | sales.incentive.submit, sales.incentive.view | sales.incentive.view |
| POST | /sales/incentives/{id}/decision | SalesIncentiveController::decide | A | sales.incentive.approve, sales.incentive.view | sales.incentive.view |
| POST | /sales/incentives/{id}/settlements | SalesIncentiveController::settle | A | sales.incentive.settle, sales.incentive.view | sales.incentive.view |
| POST | /sales/quick-sale | SalesController::storeQuickSale | A | sales.quick_sale.use | sales.quick_sale.use OR sales.quick_sale.review |
| POST | /sales/quick-sale/{id}/reports/{reportId}/confirm | SalesController::confirmQuickSaleReport | A | sales.report.review | sales.quick_sale.use OR sales.quick_sale.review |
| POST | /sales/quick-sale/{id}/reports/{reportId}/correction | SalesController::returnQuickSaleReportForCorrection | A | sales.report.review | sales.quick_sale.use OR sales.quick_sale.review |
| GET | /sales/quick-sale/{id}/reports/{reportId}/evidence | SalesController::quickSaleEvidence | A | finance.settlements.approve, finance.settlements.reconcile, finance.settlements.view, sales.report.review, sales.report.submit, sales.settlements.review | sales.quick_sale.use OR sales.quick_sale.review |
| GET | /sales/quick-sale/{id} | SalesController::showQuickSale | B | finance.records.view, finance.settlements.approve, finance.settlements.reconcile, finance.settlements.view, sales.quick_sale.review, sales.quick_sale.use, sales.report.review, sales.report.submit, sales.settlements.review | sales.quick_sale.use OR sales.quick_sale.review |
| POST | /sales/quick-sale/{id}/confirm | SalesController::confirmQuickSale | A | sales.quick_sale.review | sales.quick_sale.use OR sales.quick_sale.review |
| POST | /sales/quick-sale/{id}/report | SalesController::reportQuickSale | A | sales.report.submit | sales.quick_sale.use OR sales.quick_sale.review |
| POST | /sales/quick-sale/{id}/escalate | SalesController::escalateQuickSale | A | sales.orders.confirm | sales.quick_sale.use OR sales.quick_sale.review |
| POST | /sales/quick-sale/{id}/reports/{reportId}/handoff | SalesController::handoffQuickSale | A | sales.report.review | sales.quick_sale.use OR sales.quick_sale.review |
| GET | /sales/quotations/create | SalesController::createQuotation | A | sales.orders.create, sales.orders.submit, sales.quotation | sales.quotations.view |
| GET | /sales/quotations/{id}/edit | SalesController::editQuotation | A | sales.orders.create, sales.orders.submit, sales.quotation | sales.quotations.view |
| GET | /sales/quotations/{id} | SalesController::showQuotation | B | sales.orders.create, sales.orders.submit, sales.quotation, sales.view | sales.quotations.view |
| GET | /sales/orders | SalesController::orders | B | sales.catalogue.manage, sales.commissions.manage, sales.index, sales.orders.approve, sales.orders.cancel, sales.orders.confirm, sales.orders.create, sales.orders.submit, sales.payments.record, sales.pricing.manage, sales.pricing.view, sales.reports.export, sales.serials.manage, sales.targets.manage, sales.view | sales.orders.view |
| GET | /sales/orders/{id} | SalesController::showOrder | B | finance.records.manage, sales.order, sales.orders.confirm, sales.view | sales.orders.view |
| POST | /sales/orders/{id}/invoices | SalesController::createInvoice | B | finance.records.manage, sales.view | sales.orders.view |
| POST | /sales/orders/{id}/credit-notes | SalesController::createCreditNote | B | finance.records.manage, sales.view | sales.orders.view |
| GET | /sales/pricelists | SalesController::pricelists | B | sales.catalogue.manage, sales.commissions.manage, sales.index, sales.orders.approve, sales.orders.cancel, sales.orders.confirm, sales.orders.create, sales.orders.submit, sales.payments.record, sales.pricing.manage, sales.pricing.view, sales.reports.export, sales.serials.manage, sales.targets.manage, sales.view | sales.pricing.view |
| GET | /sales/teams | SalesController::teams | B | sales.catalogue.manage, sales.commissions.manage, sales.index, sales.orders.approve, sales.orders.cancel, sales.orders.confirm, sales.orders.create, sales.orders.submit, sales.payments.record, sales.pricing.manage, sales.pricing.view, sales.reports.export, sales.serials.manage, sales.targets.manage, sales.view | sales.catalogue.manage |
| GET | /sales/pricelists/{id} | SalesController::showPricelist | A | sales.catalogue.manage, sales.commercial, sales.pricing.manage, sales.pricing.view | sales.pricing.view |
| GET | /sales/teams/{id} | SalesController::showTeam | A | sales.catalogue.manage, sales.commercial, sales.pricing.manage | sales.catalogue.manage |
| GET | /sales/deliveries | SalesController::deliveries | B | sales.deliveries, sales.view | sales.deliveries.view |
| GET | /sales/deliveries/{id} | SalesController::showDelivery | B | inventory.deliveries.validate, sales.delivery, sales.view | sales.deliveries.view |
| POST | /sales/deliveries/{id}/complete | SalesController::completeDelivery | A | inventory.deliveries.validate | sales.deliveries.view |
| POST | /sales/deliveries/{id}/reserve | SalesController::reserveDelivery | A | inventory.deliveries.validate | sales.deliveries.view |
| POST | /sales/deliveries/{id}/returns | SalesController::createReturn | A | inventory.deliveries.validate | sales.deliveries.view |
| POST | /sales/customers | SalesController::storeCustomer | A | sales.catalogue.manage | sales.customers.view |
| POST | /sales/products | SalesController::storeProduct | A | sales.catalogue.manage | sales.products.view |
| POST | /sales/customers/{id} | SalesController::updateCustomer | A | sales.catalogue.manage | sales.customers.view |
| POST | /sales/customers/{id}/active | SalesController::toggleCustomer | A | sales.catalogue.manage | sales.customers.view |
| POST | /sales/products/{id} | SalesController::updateProduct | A | sales.catalogue.manage | sales.products.view |
| POST | /sales/products/{id}/active | SalesController::toggleProduct | A | sales.catalogue.manage | sales.products.view |
| POST | /sales/territories | SalesController::storeTerritory | A | sales.catalogue.manage | Controller contract |
| POST | /sales/agents | SalesController::storeAgent | A | sales.catalogue.manage | Controller contract |
| POST | /sales/targets | SalesController::storeTarget | A | sales.targets.manage | Controller contract |
| POST | /sales/orders | SalesController::storeOrder | A | sales.orders.create | sales.orders.view |
| POST | /sales/quotations | SalesController::storeQuotation | A | sales.orders.create | sales.quotations.view |
| POST | /sales/quotations/{id} | SalesController::updateQuotation | A | sales.orders.create | sales.quotations.view |
| POST | /sales/quotations/{id}/send | SalesController::sendQuotation | A | sales.orders.submit | sales.quotations.view |
| POST | /sales/quotations/{id}/confirm | SalesController::confirmQuotation | A | sales.orders.submit | sales.quotations.view |
| POST | /sales/quotations/{id}/cancel | SalesController::cancelQuotation | A | sales.orders.submit | sales.quotations.view |
| POST | /sales/quotations/action | SalesController::transitionQuotation | A | sales.orders.submit | sales.quotations.view |
| POST | /sales/pricelists | SalesController::storePricelist | A | sales.pricing.manage | sales.pricing.view |
| POST | /sales/teams | SalesController::storeTeam | A | sales.catalogue.manage | sales.catalogue.manage |
| POST | /sales/pricelists/{id} | SalesController::updatePricelist | A | sales.pricing.manage | sales.pricing.view |
| POST | /sales/pricelists/{id}/rules | SalesController::storePricelistRule | A | sales.pricing.manage | sales.pricing.view |
| POST | /sales/pricelists/{id}/rules/{ruleId} | SalesController::updatePricelistRule | A | sales.pricing.manage | sales.pricing.view |
| POST | /sales/pricelists/{id}/rules/{ruleId}/active | SalesController::togglePricelistRule | A | sales.pricing.manage | sales.pricing.view |
| POST | /sales/pricelists/{id}/active | SalesController::togglePricelist | A | sales.pricing.manage | sales.pricing.view |
| POST | /sales/teams/{id} | SalesController::updateTeam | A | sales.catalogue.manage | sales.catalogue.manage |
| POST | /sales/teams/{id}/active | SalesController::toggleTeam | A | sales.catalogue.manage | sales.catalogue.manage |
| POST | /sales/orders/action | SalesController::transitionOrder | A | sales.orders.approve, sales.orders.cancel, sales.orders.confirm, sales.orders.submit | sales.orders.view |
| POST | /sales/serials | SalesController::storeSerialNumbers | A | sales.serials.manage | Controller contract |
| POST | /sales/commissions/action | SalesController::transitionCommission | A | sales.commissions.manage | Controller contract |
| POST | /sales/payments | SalesController::recordPayment | A | sales.payments.record | Controller contract |
| GET | /sales/export | SalesController::export | A | sales.reports.export | Controller contract |
| GET | /organization/setup | OrganizationSetupController::index | B | administration.users.manage, hr.records.manage, hr.records.view, organization.branches.manage, organization.branches.view, organization.departments.manage, organization.departments.view, organization.job_titles.manage, organization.job_titles.view, organization.positions.manage, organization.positions.view, organization.setup | Controller contract |
| GET | /organization/branches | BranchController::index | A | organization.branches.index, organization.branches.manage, organization.branches.view | Controller contract |
| GET | /organization/branches/create | BranchController::create | A | organization.branches.form, organization.branches.manage | Controller contract |
| POST | /organization/branches | BranchController::store | A | organization.branches.manage | Controller contract |
| GET | /organization/branches/edit | BranchController::edit | A | organization.branches.form, organization.branches.manage | Controller contract |
| POST | /organization/branches/update | BranchController::update | A | organization.branches.manage | Controller contract |
| GET | /organization/job-titles | JobTitleController::index | A | organization.job_titles.manage, organization.job_titles.view | Controller contract |
| GET | /organization/job-titles/create | JobTitleController::create | A | organization.job_titles.manage | Controller contract |
| POST | /organization/job-titles | JobTitleController::store | A | organization.job_titles.manage | Controller contract |
| GET | /organization/job-titles/edit | JobTitleController::edit | A | organization.job_titles.manage | Controller contract |
| POST | /organization/job-titles/update | JobTitleController::update | A | organization.job_titles.manage | Controller contract |
| GET | /organization/departments | DepartmentController::index | A | organization.departments.index, organization.departments.manage, organization.departments.view | Controller contract |
| GET | /organization/departments/create | DepartmentController::create | A | organization.departments.form, organization.departments.manage | Controller contract |
| POST | /organization/departments | DepartmentController::store | A | organization.departments.manage | Controller contract |
| GET | /organization/departments/edit | DepartmentController::edit | A | organization.departments.form, organization.departments.manage | Controller contract |
| POST | /organization/departments/update | DepartmentController::update | A | organization.departments.manage | Controller contract |
| GET | /organization/positions | PositionController::index | A | organization.positions.index, organization.positions.manage, organization.positions.view | Controller contract |
| GET | /organization/positions/create | PositionController::create | A | organization.positions.form, organization.positions.manage | Controller contract |
| POST | /organization/positions | PositionController::store | A | organization.positions.manage | Controller contract |
| GET | /organization/positions/edit | PositionController::edit | A | organization.positions.form, organization.positions.manage | Controller contract |
| POST | /organization/positions/update | PositionController::update | A | organization.positions.manage | Controller contract |
| GET | /hr/employees/view | HrController::show | B | administration.users.manage, hr.records.manage, hr.records.view, hr.show | Controller contract |
| GET | /hr/employees/activity | EmployeeActivityController::index | B | hr.employees.activity, hr.records.manage, hr.records.view | Controller contract |
| GET | /hr/employees/position | EmployeePositionController::edit | B | hr.employees.position, hr.records.manage, organization.positions.manage | Controller contract |
| POST | /hr/employees/position | EmployeePositionController::update | B | hr.records.manage | Controller contract |
| GET | /hr/employees/create | HrController::createEmployee | B | hr.employees.create, hr.records.manage | Controller contract |
| GET | /hr/employees/edit | HrController::editEmployee | B | hr.employees.create, hr.records.manage | Controller contract |
| POST | /hr/employees | HrController::storeEmployee | B | hr.records.manage | Controller contract |
| POST | /hr/employees/update | HrController::updateEmployee | B | hr.records.manage | Controller contract |
| GET | /hr/departments | HrController::departments | B | hr.departments.index, hr.records.manage | Controller contract |
| GET | /hr/departments/create | HrController::createDepartment | B | hr.departments.create, hr.records.manage | Controller contract |
| GET | /hr/departments/edit | HrController::editDepartment | B | hr.departments.create, hr.records.manage | Controller contract |
| POST | /hr/departments | HrController::storeDepartment | B | hr.records.manage | Controller contract |
| POST | /hr/departments/update | HrController::updateDepartment | B | hr.records.manage | Controller contract |
| GET | /administration/audit-logs | AuditLogController::index | A | audit.logs.view | Controller contract |
| GET | /administration/modules | ModuleAdministrationController::index | A | administration.modules.index, Explicit platform administrator flag | Controller contract |
| POST | /administration/modules | ModuleAdministrationController::update | A | Explicit platform administrator flag | Controller contract |
| GET | /administration/companies | CompanyAdministrationController::index | A | administration.companies.index, Explicit platform administrator flag | Controller contract |
| GET | /administration/companies/create | CompanyAdministrationController::create | A | administration.companies.create, Explicit platform administrator flag | Controller contract |
| GET | /administration/companies/view | CompanyAdministrationController::show | A | administration.companies.show, Explicit platform administrator flag | Controller contract |
| GET | /administration/companies/edit | CompanyAdministrationController::edit | A | administration.companies.edit, Explicit platform administrator flag | Controller contract |
| GET | /administration/companies/reset-owner-password | CompanyAdministrationController::showOwnerPasswordReset | A | Explicit platform administrator flag | Controller contract |
| GET | /administration/companies/reset-user-password | CompanyAdministrationController::showCompanyUserPasswordReset | A | Explicit platform administrator flag | Controller contract |
| POST | /administration/companies | CompanyAdministrationController::store | A | Explicit platform administrator flag | Controller contract |
| POST | /administration/companies/update | CompanyAdministrationController::update | A | Explicit platform administrator flag | Controller contract |
| POST | /administration/companies/approve | CompanyAdministrationController::approve | A | Explicit platform administrator flag | Controller contract |
| POST | /administration/companies/reset-owner-password | CompanyAdministrationController::resetOwnerPassword | A | Explicit platform administrator flag | Controller contract |
| POST | /administration/companies/reset-user-password | CompanyAdministrationController::resetCompanyUserPassword | A | Explicit platform administrator flag | Controller contract |
| POST | /administration/companies/lifecycle | CompanyAdministrationController::changeLifecycle | A | Explicit platform administrator flag | Controller contract |
| GET | /administration/audit-logs/view | AuditLogController::show | A | administration.users.manage, audit.logs.view | Controller contract |
| GET | /administration/users/activity | UserActivityController::index | A | administration.users.activity, administration.users.manage, audit.logs.view | administration.users.manage |
| GET | /administration/roles | RoleAdministrationController::index | A | administration.roles.index, administration.roles.manage | Controller contract |
| GET | /administration/roles/view | RoleAdministrationController::show | A | administration.roles.manage, administration.roles.show | Controller contract |
| GET | /administration/roles/edit-permissions | RoleAdministrationController::editPermissions | A | administration.roles.manage | Controller contract |
| POST | /administration/roles/update-permissions | RoleAdministrationController::updatePermissions | A | administration.roles.manage | Controller contract |
| GET | / | HomeController::index | G |  | Controller contract |
| GET | /health | HomeController::health | G |  | Controller contract |
| GET | /login | AuthController::showLogin | G |  | Controller contract |
| POST | /login | AuthController::login | G |  | Controller contract |
| GET | /change-password | AuthController::showChangePassword | G |  | Controller contract |
| POST | /change-password | AuthController::changePassword | G |  | Controller contract |
| GET | /dashboard | DashboardController::index | A | dashboard.index, dashboard.view | dashboard.view |
| POST | /dashboard/sessions/{id}/terminate | AuthenticatedSessionController::terminateDashboardSession | F |  | Controller contract |
| POST | /dashboard/sessions/terminate-others | AuthenticatedSessionController::terminateDashboardOthers | F |  | Controller contract |
| GET | /analytics | PowerBiController::index | A | analytics.index, analytics.view | analytics.view |
| GET | /administration/integration-events | IntegrationEventController::index | A | administration.integration_events.retry, administration.integration_events.view | Controller contract |
| POST | /administration/integration-events/{id}/retry | IntegrationEventController::retry | A | administration.integration_events.retry, administration.integration_events.view | Controller contract |
| GET | /finance/accounting-periods | FinanceController::accountingPeriods | A | finance.period.view | finance.period.view |
| GET | /finance/accounting/{section} | FinanceAccountingController::show | B | finance.records.view | Controller contract |
| GET | /finance/statements/{kind} | FinanceAccountingController::statement | B | finance.records.view, finance.statement | Controller contract |
| GET | /finance/reconciliation | FinanceAccountingController::reconciliation | B | finance.reconciliation, finance.records.view | finance.reports.view |
| GET | /finance/bank-reconciliation | FinanceBankReconciliationController::index | A | finance.bank_reconciliation.view | finance.bank_reconciliation.view |
| POST | /finance/bank-reconciliation/mappings | FinanceBankReconciliationController::createMapping | A | finance.bank_reconciliation.mapping | finance.bank_reconciliation.view |
| POST | /finance/bank-reconciliation/mappings/{id}/approve | FinanceBankReconciliationController::approveMapping | A | finance.bank_reconciliation.mapping | finance.bank_reconciliation.view |
| POST | /finance/bank-reconciliation/statements | FinanceBankReconciliationController::createStatement | A | finance.bank_reconciliation.prepare | finance.bank_reconciliation.view |
| GET | /finance/bank-reconciliation/{id} | FinanceBankReconciliationController::show | A | finance.bank_reconciliation.view | finance.bank_reconciliation.view |
| POST | /finance/bank-reconciliation/{id}/lines | FinanceBankReconciliationController::addLine | A | finance.bank_reconciliation.prepare | finance.bank_reconciliation.view |
| POST | /finance/bank-reconciliation/{id}/matches | FinanceBankReconciliationController::match | A | finance.bank_reconciliation.prepare | finance.bank_reconciliation.view |
| POST | /finance/bank-reconciliation/{id}/matches/{matchId}/remove | FinanceBankReconciliationController::unmatch | A | finance.bank_reconciliation.prepare | finance.bank_reconciliation.view |
| POST | /finance/bank-reconciliation/{id}/submit | FinanceBankReconciliationController::sendForReview | A | finance.bank_reconciliation.prepare | finance.bank_reconciliation.view |
| POST | /finance/bank-reconciliation/{id}/review | FinanceBankReconciliationController::review | A | finance.bank_reconciliation.review | finance.bank_reconciliation.view |
| GET | /finance/expenses | FinanceExpenseController::index | B | finance.expenses, finance.records.view | finance.expenses.view |
| POST | /finance/expenses | FinanceExpenseController::create | B | finance.records.manage | finance.expenses.view |
| POST | /finance/expenses/{id}/edit | FinanceExpenseController::edit | B | finance.records.manage | finance.expenses.view |
| POST | /finance/expenses/{id}/submit | FinanceExpenseController::submit | B | finance.records.manage | finance.expenses.view |
| POST | /finance/expenses/{id}/cancel | FinanceExpenseController::cancel | B | finance.records.manage | finance.expenses.view |
| POST | /finance/expenses/{id}/review | FinanceExpenseController::review | A | finance.requests.approve | finance.expenses.view |
| POST | /finance/expenses/{id}/pay | FinanceExpenseController::pay | B | finance.records.manage | finance.expenses.view |
| POST | /finance/expenses/{id}/recognize | FinanceExpenseController::recognize | B | finance.records.manage | finance.expenses.view |
| POST | /finance/expenses/{id}/reverse | FinanceExpenseController::reverse | A | finance.requests.approve | finance.expenses.view |
| POST | /finance/expenses/{id}/evidence | FinanceExpenseController::addEvidence | B | finance.records.manage | finance.expenses.view |
| POST | /finance/expenses/{id}/evidence/{evidenceId}/remove | FinanceExpenseController::removeEvidence | B | finance.records.manage | finance.expenses.view |
| GET | /finance/expenses/{id}/evidence/{evidenceId} | FinanceExpenseController::evidence | B | finance.records.view | finance.expenses.view |
| POST | /finance/expense-categories/{id}/defaults | FinanceExpenseController::categoryDefaults | B | finance.records.manage | Controller contract |
| GET | /finance/staff-loans | FinanceStaffLoanController::index | B | finance.records.view | finance.staff_loans.view |
| POST | /finance/staff-loans | FinanceStaffLoanController::create | B | finance.records.manage | finance.staff_loans.view |
| GET | /finance/staff-loans/{id} | FinanceStaffLoanController::detail | B | finance.records.view | finance.staff_loans.view |
| POST | /finance/staff-loans/{id}/submit | FinanceStaffLoanController::submit | B | finance.records.manage | finance.staff_loans.view |
| POST | /finance/staff-loans/{id}/cancel | FinanceStaffLoanController::cancel | B | finance.records.manage | finance.staff_loans.view |
| POST | /finance/staff-loans/{id}/review | FinanceStaffLoanController::review | A | finance.requests.approve | finance.staff_loans.view |
| POST | /finance/staff-loans/{id}/disburse | FinanceStaffLoanController::disburse | B | finance.records.manage | finance.staff_loans.view |
| POST | /finance/staff-loans/{id}/repay | FinanceStaffLoanController::repay | B | finance.records.manage | finance.staff_loans.view |
| POST | /finance/fiscal-years | FinanceController::createFiscalYear | A | finance.period.manage | Controller contract |
| POST | /finance/accounting-periods | FinanceController::createAccountingPeriod | A | finance.period.manage | finance.period.view |
| POST | /finance/accounting-periods/{id}/transition | FinanceController::transitionAccountingPeriod | A | finance.period.close, finance.period.reopen | finance.period.view |
| GET | /administration/analytics | PowerBiController::configuration | A | administration.analytics, analytics.configure | Controller contract |
| POST | /administration/analytics | PowerBiController::save | A | analytics.configure | Controller contract |
| POST | /administration/analytics/validate | PowerBiController::validateConfiguration | A | analytics.configure | Controller contract |
| POST | /administration/analytics/enable | PowerBiController::enable | A | analytics.configure | Controller contract |
| GET | /administration | AdministrationController::index | A | administration.companies.manage, administration.index, administration.modules.manage, administration.roles.manage, administration.users.manage, audit.logs.view, organization.branches.manage, organization.branches.view, organization.departments.manage, organization.departments.view, organization.job_titles.manage, organization.job_titles.view, organization.positions.manage, organization.positions.view | Controller contract |
| POST | /logout | AuthController::logout | G |  | Controller contract |
| POST | /company/switch | CompanyContextController::switch | F |  | Controller contract |
| GET | /administration/users/create | UserAdministrationController::create | A | administration.roles.manage, administration.users.create, administration.users.manage | administration.users.manage |
| GET | /administration/users/view | UserAdministrationController::show | A | administration.roles.manage, administration.users.manage, administration.users.show, audit.logs.view, inventory.warehouse_access.manage | administration.users.manage |
| GET | /administration/users/edit | UserAdministrationController::edit | A | administration.roles.manage, administration.users.edit, administration.users.manage, Explicit platform administrator flag | administration.users.manage |
| POST | /administration/users/update | UserAdministrationController::update | A | administration.roles.manage, administration.users.manage | administration.users.manage |
| GET | /administration/users/inventory-access | UserAdministrationController::inventoryAccess | A | administration.users.manage, inventory.warehouse_access.manage | administration.users.manage |
| POST | /administration/users/inventory-access | UserAdministrationController::saveInventoryAccess | A | administration.users.manage, inventory.warehouse_access.manage | administration.users.manage |
| POST | /administration/users/sessions/terminate | AuthenticatedSessionController::terminateAdminSession | F | administration.users.manage | administration.users.manage |
| POST | /administration/users/sessions/terminate-all | AuthenticatedSessionController::terminateAdminAll | F | administration.users.manage | administration.users.manage |
| GET | /administration/users/reset-password | UserAdministrationController::showResetPassword | A | administration.users.manage, Explicit platform administrator flag | administration.users.manage |
| POST | /administration/users/reset-password | UserAdministrationController::resetPassword | A | administration.users.manage | administration.users.manage |
| GET | /administration/users/account-status | UserAdministrationController::showAccountStatus | A | administration.users.manage, Explicit platform administrator flag | administration.users.manage |
| POST | /administration/users/account-status | UserAdministrationController::changeAccountStatus | A | administration.users.manage | administration.users.manage |
| GET | /administration/users/unlock | UserAdministrationController::showUnlockAccount | A | administration.users.manage, administration.users.unlock, Explicit platform administrator flag | administration.users.manage |
| POST | /administration/users/unlock | UserAdministrationController::unlockAccount | A | administration.users.manage | administration.users.manage |
| POST | /administration/users | UserAdministrationController::store | A | administration.roles.manage, administration.users.manage | administration.users.manage |
| POST | /notifications/read-all | NotificationController::readAll | F |  | Controller contract |
| POST | /notifications/{id}/read | NotificationController::read | F |  | Controller contract |
| GET | /procurement/requisitions/{id} | ProcurementController::showRequisition | B | procurement.requisition, procurement.view | procurement.requisitions.view |
| POST | /procurement/requisitions/{id}/resubmit | ProcurementController::resubmitRequisition | A | procurement.requisitions.create | procurement.requisitions.view |
| POST | /sales/orders/{id}/resubmit | SalesController::resubmitOrder | A | sales.orders.create, sales.orders.submit | sales.orders.view |
