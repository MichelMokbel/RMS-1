# Spend approval and settlement

## Overview

Spend uses AP expense invoices plus expense profiles and events. It separates approval, accounting posting, and settlement for vendor, petty cash, and reimbursement channels.

## Key files

| File | Owns |
|---|---|
| `ExpenseWorkflowService.php` | Submission, staged approval, rejection, posting, and settlement orchestration. |
| `ExpenseApprovalPolicyService.php` | Exception flags and finance approval requirements. |
| `ExpenseSettlementService.php` | AP payment and wallet settlement effects. |
| `ExpenseEventService.php` | Expense lifecycle history. |
| `SpendReportService.php` | Canonical expense reporting. |
| `app/Models/ExpenseProfile.php`, `config/spend.php` | Channel, approval, and settlement state. |

## Conventions

* `approval_status` on the expense profile and AP invoice `status` describe different lifecycle stages.
* Submission can require manager approval followed by finance approval. The service has an explicit admin approval path, so you can preserve its distinction from ordinary submitter restrictions.
* Posting delegates to `ApInvoicePostingService`. Settlement delegates to AP allocation and, for petty cash, wallet behavior.
* Rejection reasons and state changes are recorded as expense events.

## Gotchas

* Legacy `/api/expenses` mutations return HTTP 410. New integrations belong on the canonical AP expense flow, not the old expense tables.
* Posted or paid expense correction belongs to `app/Services/AP/PaidExpenseCorrectionService.php`, which has shared payment restrictions.
* Relevant tests are `tests/Feature/Spend/SpendExpensesApiTest.php`, `tests/Feature/Spend/SpendHubAuthorizationTest.php`, and `tests/Feature/Reports/SpendReportAggregationTest.php`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
