# abraflexi-domain-credit

MultiFlexi application: adds the ordered Subreg domain credit (`KREDIT_DOMENA`) for a **paid** AbraFlexi issued invoice.

Headless successor of `SpojeNet\System\orderplugins\DomainCredit::settled()` from the `system` eShop.

## How it works

1. Takes the invoice from `INVOICE_ID` (or `EVENT_RECORD_ID` provided by a MultiFlexi event rule, or `--invoice`).
2. Sums `mnozMj * cenaMj` of the `KREDIT_DOMENA` items. Invoices without the item are skipped (exit 0).
3. Requires the `API` label on the invoice (the "pending fulfilment" flag).
4. Finds the Subreg user via the customer's `ext:subreg:<id>` and calls `Credit_Correction`.
5. Removes the `API` label **only after the credit was added**. If Subreg fails the label stays, so the job can be retried.

Result is written as JSON to `RESULT_FILE`. Exit codes: `0` ok/nothing to do, `1` failed, `2` missing invoice id.

## Configuration

`ABRAFLEXI_URL|LOGIN|PASSWORD|COMPANY`, `SUBREG_LOCATION|URI|LOGIN|PASSWORD`, optional `INVOICE_ID`, `RESULT_FILE`, `APP_DEBUG`.

## Development

```
composer install
vendor/bin/phpunit
```
