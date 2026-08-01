# Sales module ToR compliance

This matrix records the implemented Sales scope against the ERP Terms of Reference.

| ToR capability | Implementation | Verification |
|---|---|---|
| Customer database | Company-isolated customers, contact, tax, territory, credit limits and terms | Sales integration test |
| Order processing | Multi-line draft, submission, independent approval, fulfilment and controlled cancellation | Sales integration test |
| Product tracking | Telecom catalogue with ISO currency, prices, tax, discounts and commission rates | Sales integration test |
| Serial tracking | IMEI/ICCID/device/voucher registry with duplicate protection and allocation-ready records | Sales integration test |
| Receivables | Unique receipts, partial/full payment, ageing and credit-limit enforcement | Sales integration test |
| Targets and territories | Period, territory and DSA/DSP targets with approved-sales achievement | Sales integration test |
| Commission automation | Commission accrues after approval, then follows accrued → approved → paid controls | Sales integration test |
| Finance and Inventory | Idempotent approved-order, receipt and stock-commitment projections | Sales integration test |
| Security and audit | Tenant isolation, CSRF, separated permissions and audit events | Main regression suite |
| Reporting | Dashboard, overdue, commission, targets and CSV export | Contract test and UI smoke test |

## Order lifecycle

`draft → submitted → approved → fulfilled`

Approved or fulfilled orders may receive payments and become `partially_paid`
or `paid`. Cancellation requires no prior payment and a meaningful reason.
Existing `confirmed` records remain supported for backward compatibility.

## Release acceptance

1. Run migrations and reference-data synchronization on an isolated copy.
2. Run `php tests/sales-module-contract.php` and `php tests/sales-integration.php`.
3. Run the complete isolated test stack and compare unrelated known failures.
4. Approve a sample order and dispatch its integration events.
5. Confirm the Finance receivable and Inventory commitments.
6. Verify access with an owner, creator and read-only user.
