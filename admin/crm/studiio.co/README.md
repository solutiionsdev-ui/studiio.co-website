# studiio.co client system: settings and wording

Everything a client reads, and every value you might change, lives in this folder. The admin's
**Pipeline** reads it on every request, so a change shows up straight away (on this Mac at once,
online after you publish). This folder is blocked from the web.

After any edit, check it:

```bash
php admin/tools/check-crm.php
```

## Files

| File | What it is |
| --- | --- |
| `settings.json` | Tracks, sources, contact details on documents, payment terms and timings (the onboarding spec's section 10), next action per stage |
| `templates/en/checklist.json` | The asset and access checklist per track |
| `templates/en/intake.json` | The intake questionnaire (questions, page wording) |
| `templates/en/proposal.md` | Proposal |
| `templates/en/agreement.md` | Service agreement (an operating template, not legal advice: have a Lebanese lawyer review it once) |
| `templates/en/welcome_pack.md` | Welcome pack |
| `templates/en/kickoff_agenda.md` | Kickoff agenda (for you, at the call) |
| `templates/en/feedback_rules.md` | Feedback rules, included in the welcome pack and the agreement |
| `templates/en/messages.json` | Messages: the links you send, kickoff recap, handover, testimonial, referral, check-in, renewal |
| `templates/en/labels.json` | Other words on client pages and PDFs: document titles, "Prepared for", "Download PDF", the invoice wording (`invoice`), the client portal (`portal`, including what the client sees for each stage) and signing (`sign`) |

The quotation's and invoices' layout is built in (black band, one flat total, payment terms or how to pay, notes); their words come from `labels.json`. Your bank or Whish details are not here (this folder is public on GitHub): type them on the admin's Payments page.

Arabic later: add `templates/ar/` with the same files.

## How templates work

- `{{name}}` is replaced with a value (list below).
- `{{#name}}…{{/name}}` keeps the part only when the value is set; `{{^name}}…{{/name}}` only when it is not.
- `{{#retainer}}`, `{{#production}}` and `{{#photography}}` keep a part for that track only.
- `{{> feedback_rules}}` includes another template.
- In `.md` files: `# Title`, `## Section`, `- bullet`, `1. numbered`, `**bold**`, and `[page]` for a new page.
- Lines starting with `%%` are notes for us and never reach the client.

## Values

| Group | Placeholders |
| --- | --- |
| Client | `client_name`, `business_name`, `contact_name`, `contact_first_name`, `channel_label` |
| Project | `project_title`, `track_label`, `track_about`, `scope`, `deliverables_list`, `exclusions_list`, `deliverables_summary`, `approver`, `approver_or_one_person`, `delivery_link`, `link_expiry_date` |
| Money | `price`, `currency`, `deposit_percent`, `deposit_amount` (a retainer's first month), `paid`, `balance_amount`, `payment_schedule`, `payment_schedule_lower`, `payment_methods` |
| Dates | `today`, `start_date`, `end_date`, `kickoff_date`, `first_shoot`, `draft_date`, `delivery_date`, `checklist_due` |
| Checklist | `checklist_list` (still waiting), `checklist_open_list` (required and still waiting) |
| From the intake | `client_goal`, `success`, `deadline`, `usage`, `references`, `no_go`, `location`, `on_camera` |
| Terms (settings) | `revision_rounds`, `feedback_days`, `reply_days_text`, `reschedule_hours`, `retainer_min_months`, `retainer_notice_days`, `unused_visits_roll_over`, `raw_footage`, `raw_footage_lower`, `delivery_link_days`, `checkin_days`, `signing_lower` |
| Studiio | `contact_person`, `contact_email`, `contact_phone`, `contact_instagram`, `reply_hours` |
| Messages | `link` (the document, questionnaire or portal link being sent), `invoice_no`, `invoice_amount` (still due), `invoice_due`, `last_payment`, `round` (the latest shared round) |
| Retainers | `month`, `next_month`, `delivered_summary` (what was delivered this month), `next_plan` (next month's plan, once written), `visits_used`, `visits_per_month` |
