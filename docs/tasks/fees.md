# Build fees: heads, rates, waivers, monthly dues, payments and receipts

Status: done
Branch: feat/fees

## Problem
Fees are still stubs:
- `FeeTypeController`, `FeeStructureController` and `FeePaymentController` return `{data: []}` or 501.
- `fee-payments/student/...` and `fee-payments/receipt/...` point at methods that don't exist, so they return 500.
- The `fee_types`, `fee_structures` and `fee_payments` tables were never used.
- The admin Fees view is an 8-line placeholder.
- The parent and student roles hold `view-fees` for everyone, so they could read every student's fees.

The intended outcome, using Bangladeshi Taka (BDT, ৳) stored and sent as `decimal:2`:
- Admins define fee heads and the rate for each class and year.
- Admins and office staff (via `create-fees`) give individual students waivers.
- Admins generate monthly (Jan–Dec), one-time and per-exam dues for enrolled students.
- Office staff take cash or bKash/Nagad/Rocket payments (transaction ID entered by hand), including part payments.
- Each payment gets a printable receipt.
- Reports show dues and collections.
- Students and guardians see only their own dues and payments.

This is Task 5 of 7 in `/Users/adil/.claude/plans/make-the-plan-for-radiant-flurry.md`. Read the Task 5 section there.

## Scope
**In:**

1. **Tables.** Drop the unused `fee_payments`, `fee_structures` and `fee_types` tables, together with their models, stub controllers and routes, and the broken `fee-payments/*` routes. `down()` recreates the old structure without data. Then create:

   | Table | Columns and keys |
   |---|---|
   | `fee_heads` (soft deletes) | `name_en`, `name_bn` (at least one required), `code` (unique, uppercased), `kind` (`monthly`, `one_time` or `per_exam`), `is_active` |
   | `fee_rates` | `fee_head_id`, `class_id`, `academic_year_id`, nullable `group`, `amount` (decimal 10,2, min 0), `due_day` (1–28, nullable, used for monthly heads; a due-day default) |
   | `student_fee_waivers` | `student_id`, `academic_year_id`, `fee_head_id`, `percent` (0–100, decimal 5,2) **or** `fixed_amount` (decimal 10,2), never both, `reason`, `approved_by` (user) |
   | `fee_dues` | `student_id`, `enrolment_id`, `fee_head_id`, `period` (`YYYY-MM` for monthly, `one_time`, or `exam:{id}`), `amount`, `waiver_amount`, `net_amount`, `paid_amount`, `status` (`unpaid`, `partial`, `paid` or `waived`), `due_date` |
   | `fee_payments` | `receipt_no` (unique, `{year}-{000001}` sequence per calendar year), `student_id`, `paid_at` (UTC timestamp), `method` (`cash`, `bkash`, `nagad` or `rocket`), `transaction_id` (required for the mobile methods, unique per method, normalised uppercase and trimmed), `amount`, `collected_by` (user), `note`, `cancelled_at`, `cancelled_by`, `cancel_reason` |
   | `fee_payment_allocations` | `fee_payment_id`, `fee_due_id`, `amount` |

   Unique keys:
   - `fee_rates`: `(fee_head_id, class_id, academic_year_id, group)`. Handle a null `group` in the service, as for curriculum rows.
   - `fee_dues`: `(enrolment_id, fee_head_id, period)`.
   - `fee_payment_allocations`: `(fee_payment_id, fee_due_id)`.

2. **Money arithmetic.** All money is handled as integer paisa through a stateless `App\Support\Money` helper: `toPaisa(string|int|float)`, `fromPaisa(int): string`, percentage rounding half-up to the paisa. Never use floats for money. API values are `decimal:2` strings.

3. **Services and rules.** Each service has its own repository and interface, bound in `RepositoryServiceProvider`.
   - **`FeeHeadService`**
     - CRUD.
     - Delete returns 409 while rates or dues use the head.
   - **`FeeRateService`**
     - CRUD.
     - A group is only allowed from Class 9.
     - A rate with no group applies to the whole class.
     - A group-specific rate wins over the no-group rate.
     - One rate per head, class, year and group. Duplicates are 422.
     - Rates already used by dues can be edited, but existing dues keep their amount.
     - Delete returns 409 while dues reference it, through head, class and year.
   - **`FeeWaiverService`**
     - CRUD.
     - A waiver needs exactly one of percent or fixed amount.
     - Waivers affect dues generated after the change.
     - Optionally offer "re-apply to unpaid dues". Do it only if simple, and document what was chosen.
   - **`FeeDueService::generate({academic_year_id, month?: 'YYYY-MM', class_id?, section_id?, fee_head_id?, exam_id?})`**
     - Creates dues for active enrolments in the target.
     - Monthly heads: one due per month for the given month, or every month Jan–Dec up to the current month in Asia/Dhaka when no month is given.
     - One-time heads: once per enrolment and year.
     - Per-exam heads: once per exam, only when `exam_id` is given.
     - It's idempotent. An existing `(enrolment, head, period)` is skipped, never duplicated or overwritten.
     - `amount` comes from the applicable rate. If there's no rate, no due is created.
     - Waivers apply at generation: `waiver_amount` is percent of amount or the fixed amount, capped at the amount. A fully waived due gets status `waived`.
     - `due_date` is the rate's `due_day` in that month, otherwise the 10th.
     - It runs in a transaction and returns `{created, skipped}` counts.
   - **`FeePaymentService::collect({student_id, amount, method, transaction_id?, paid_at?, note?, due_ids?})`**
     - Runs in one transaction with the student row locked.
     - The amount must be more than 0 and no more than the student's total outstanding, or the outstanding total of the `due_ids` if those are given. Otherwise 422.
     - The amount is allocated to dues, oldest `due_date` first (or in the order of `due_ids`). This updates `paid_amount` and status (`partial` or `paid`).
     - The receipt number comes from a locked per-year counter. Use a `fee_receipt_counters` table locked `FOR UPDATE`, or `MAX(...)` under the student lock. A concurrent duplicate must still not happen, so prefer the counter table.
     - The mobile methods need a `transaction_id`, and a duplicate `(method, transaction_id)` is 422.
     - `paid_at` defaults to now, and can be backdated by an admin only.
   - **`FeePaymentService::cancel(payment, reason)`** (admin only)
     - Sets `cancelled_at`, `cancelled_by` and `cancel_reason`.
     - Reverses the allocations and recomputes the dues' `paid_amount` and status.
     - The receipt number stays used.
     - A cancelled payment can't be cancelled again (409).
     - No hard delete.
   - **Reports** (`FeeReportService`)
     - Dues per section and month: per student, the net amount, paid and outstanding.
     - Collection for a date range: totals by method and by collector, plus a list of receipts.
     - A student's ledger: dues and payments for the year, with the running balance.

4. **API.** All routes use numeric constraints and are mapped in `ApiAuthorizationTest`.
   - Endpoints and the permissions they need:

     | Endpoint | Permissions |
     |---|---|
     | `/api/fee-heads` | `view-fees` / `create-fees` / `edit-fees` / `delete-fees` |
     | `/api/fee-rates` | same as fee heads |
     | `/api/fee-waivers` | `view-fees`; writes need `create-fees` |
     | `POST /api/fee-dues/generate` | `create-fees` |
     | `GET /api/fee-dues?student_id=&section_id=&month=&status=` | `view-fees` |
     | `POST /api/fee-payments` | `collect-fees` |
     | `GET /api/fee-payments` | `view-fees` (filters: date range, method, collector, student) |
     | `GET /api/fee-payments/{payment}` | `view-fees` (receipt data) |
     | `POST /api/fee-payments/{payment}/cancel` | `delete-fees` (admin only) |
     | `GET /api/fee-reports/dues`, `/collection`, `/students/{student}/ledger` | `view-fees` |

   - **Role permissions** (`RolePermissionSeeder`):
     - The `office` role has `view-fees`, `create-fees` and `collect-fees`. No `edit-fees` or `delete-fees`.
     - Teachers get no fee permissions.
     - Student and parent lose `view-fees`.
   - **Own records** use the same services:
     - `GET /api/my/fees` (`role:student`) returns dues and payments with the outstanding total.
     - `GET /api/my/children/{student}/fees` (`role:parent`) returns 403 for anyone who isn't the guardian's own child, through `StudentService::findChildOf`.

5. **Existing guards.** The class, academic year and student `hasFeeStructures` and `hasFeePayments` guards now check the new tables:
   - class: rates or dues
   - year: rates or dues
   - student: dues or payments

6. **SPA.**
   - `views/fees/FeeHeads.vue` and `FeeRates.vue`: a rates grid per year and class, with an optional group.
   - Waivers on `StudentDetails.vue`.
   - `GenerateDues.vue`: year, month, class/section and head, with a preview of the counts.
   - `CollectFee.vue`:
     - search for a student by ID or name;
     - show their outstanding dues;
     - enter the amount and method, with a transaction ID for mobile methods;
     - optionally pick specific dues;
     - submit, then open the receipt.
   - `FeeReceipt.vue`: print-friendly, in Bangla or English with Bangla digits through the existing `banglaNumber.js`, on A4 or half-A4 (A5). It shows the school header, receipt number, date in Asia/Dhaka, student, class/section/roll, the allocations by head and month, total, method and transaction ID, and the collector. It shows a "CANCELLED" watermark when cancelled.
   - `FeeReports.vue`: dues per section and month, collection by date, and a student ledger.
   - Sidebar under Fees, by permission. Office staff see Collect, Dues and Reports. Admins also see Heads, Rates, Generate and Cancel.

7. **Seeders.**
   - `FeeSeeder` creates sample heads: Tuition (monthly), Session (one-time), Exam fee (per exam), Admission (one-time).
   - It sets 2026 rates per class in realistic BDT, e.g. tuition ৳500–1,200 by level, with Class 9+ Science higher.
   - It generates dues up to the current month and records a few demo payments: cash and bKash with fake `DEMO` transaction IDs.
   - It's idempotent, matched on head code, the rate unique key, the due key, and a seed marker on demo payments.

8. **Docs.**
   - Add a "Fees" note to CLAUDE.md.
   - Remove the fee stubs from the legacy and stub lists in CLAUDE.md and both guidelines.

**Out:**
- An online payment gateway.
- SMS or email receipts.
- Late fines.
- Fee concessions by sibling count.
- Accounting or general-ledger export.

## Guidelines that apply
- `docs/architecture-guidelines.md`: new modules on the pattern, replacing the stubs.
- `docs/api-response-guidelines.md`: money as `decimal:2` strings, computed reports under `data`, 201/204/409/422.
- CLAUDE.md: BDT `decimal:2`, UTC timestamps with Asia/Dhaka display and date defaults, Bangla-first receipts.

## Acceptance criteria
- [x] The old fee tables, stubs and broken routes are gone. The new migrations work on SQLite and on Docker MySQL 8, including rollback and a re-run.
- [x] Heads, rates and waivers follow their rules.
- [x] Generating dues is idempotent and applies waivers.
- [x] Payments allocate oldest-first or to the chosen dues, refuse overpayment, need a transaction ID for mobile methods, and get unique sequential receipt numbers under concurrency.
- [x] Cancelling reverses the allocations.
- [x] All money arithmetic is integer paisa, and every API amount is a `decimal:2` string.
- [x] Reports and the ledger are correct.
- [x] `/api/my/*` fees are scoped to the caller.
- [x] Permissions per role are as listed. Student and parent no longer have `view-fees`.
- [x] The SPA screens work, and the receipt prints in Bangla and English. Checked in Chrome on Docker: the Bangla receipt `২০২৬-০০০০০৩` shows Bangla digits, the allocation (Tuition, January 2026), the collector and signature lines, plus the language and paper selectors.
- [x] The full suite, Pint, `npm run build` and smoke all pass. CLAUDE.md and the guidelines are updated.

## Test cases
- [x] **Happy path.**
  - A Tuition rate of ৳800 for Class 10. Generating October creates 5 dues of `800.00`. Generating again creates 0 and skips 5.
  - A 50% tuition waiver gives a net of `400.00`. A 100% waiver gives `waived`.
  - Collecting ৳1,000 in cash against 2 dues of ৳800 gives the first `paid` and the second `partial` (`200.00`), and receipt `2026-000001`.
  - A bKash payment with transaction ID `8N7A6B5C` gets receipt `2026-000002`.
  - Cancelling the first payment puts both dues back to their state before it.
  - The ledger's running balance is correct.
- [x] **Validation (422):**
  - an amount more than outstanding;
  - an amount of 0 or negative;
  - bKash without a transaction ID;
  - a duplicate bKash transaction ID;
  - a waiver with both percent and fixed amount, or neither;
  - a percent above 100;
  - a group on a Class 8 rate;
  - a duplicate rate;
  - a malformed month;
  - a `due_id` that isn't the student's;
  - a backdated `paid_at` from a non-admin.
- [x] **409:**
  - deleting a head or rate that dues use;
  - cancelling twice;
  - deleting a class or year that has dues.
- [x] **Authorization:**
  - office can collect, generate and view, but gets 403 on cancel and on deleting a head;
  - a teacher gets 403 on everything fees;
  - a student or parent gets 403 on `/api/fee-*`;
  - a guardian gets 403 for another family's child on `/api/my/children/{student}/fees`;
  - a guest gets 401.
- [x] **Concurrency:** two payments for different students at the same moment get distinct sequential receipt numbers. Unit-test the counter lock order.
- [x] **Money:** `Money` rounding (e.g. 33.33% of 1000.00 → 333.30), and the paisa totals add up across allocations.
- [x] **Own records:** a student sees their own dues, payments and outstanding. A guardian sees each child.
- [x] **Legacy:** the old `/api/fee-types`, `/api/fee-structures` and `/api/fee-payments/student/...` routes return 404.
- [x] **Seeder:** run twice, it creates no duplicates.
- [x] **Unit:** `FeeDueServiceTest`, `FeePaymentServiceTest` and `MoneyTest` with mocked repositories.

## Docker check (MySQL 8)
- [x] `FeeSeeder` run twice: 4 heads, 64 rates, 670 dues (৳659,000.00) and 2 demo payments, with no duplicates.
- [x] **Concurrency:** 10 payments for 10 different students fired at the same time as the office user (`VHBUB-52`) all returned 201 and got unique, sequential receipts `2026-000003` to `2026-000012`. The counter ends at 12, with no deadlock and no 500.

## Follow-ups
- On the Bangla receipt, the date mixes English month and am/pm ("০১ Oct ২০২৬, ০৯:০৭ pm"). Use Bangla month names and পূর্বাহ্ন/অপরাহ্ন.
- The Bangla label for cash is "নগদ" and for Nagad "নগদ (মোবাইল)". Consider "নগদ টাকা" for cash to avoid confusion with the Nagad service.
- Counter row pre-creation isn't done. A first-of-year deadlock is handled by the 3-attempt retry.
