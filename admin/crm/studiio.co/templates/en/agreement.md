%% Service agreement (spec 5.3). Two or three pages, plain language.
%% NOTE FOR MARIO: this is an operating template, not legal advice. Have a Lebanese lawyer review it
%% once before it goes to clients.
# Service agreement

## 1. Who and what
This agreement is between **Studiio** (STUDIIO.CO) and **{{business_name}}**{{#contact_name}}, represented by {{contact_name}}{{/contact_name}} (“you”), for: **{{project_title}}**.
{{#scope}}{{scope}}{{/scope}}

## 2. What we deliver
{{#deliverables_list}}{{deliverables_list}}{{/deliverables_list}}{{^deliverables_list}}The deliverables listed in the accepted proposal.{{/deliverables_list}}

**Not included**, unless written above:
{{#exclusions_list}}{{exclusions_list}}{{/exclusions_list}}{{^exclusions_list}}- Models, locations, props and music licences
- Ad spend{{/exclusions_list}}

## 3. Timeline
{{#start_date}}We start on {{start_date}}.{{/start_date}}{{#delivery_date}} Final delivery is planned for {{delivery_date}}.{{/delivery_date}}{{^start_date}}{{^delivery_date}}We set the dates together at the kickoff call and confirm them in writing.{{/delivery_date}}{{/start_date}}
If your feedback or materials arrive late, the deadlines move by the same amount of time.

## 4. Price and payment
{{#price}}The price is **{{price}}**{{#retainer}} per month{{/retainer}}, in {{currency}}, with no tax added.{{/price}}{{^price}}The price is the one in the accepted quotation, in {{currency}}, with no tax added.{{/price}}
{{payment_schedule}}.{{^retainer}}{{#deposit_amount}} The deposit is {{deposit_amount}}.{{/deposit_amount}}{{/retainer}} We accept {{payment_methods}}.
Work starts once this agreement is signed and the {{#retainer}}first month is paid{{/retainer}}{{^retainer}}deposit is received{{/retainer}}.

## 5. Revisions
{{revision_rounds}} rounds of revisions are included per deliverable. An extra round is quoted before we do it.
{{> feedback_rules}}

## 6. What we need from you
- One decision-maker{{#approver}} ({{approver}}){{/approver}}, who gives feedback and approval.
- Feedback within {{feedback_days}} working days of each delivery.
- Access to the locations and the people we need, at the agreed times.

## 7. Rescheduling and cancelling shoot days
Shoot days can be moved with at least {{reschedule_hours}} hours’ notice. With less notice, the shoot day counts as used{{#retainer}} (for a retainer, it counts as one of the month’s visits){{/retainer}}.

## 8. Usage rights
- Once everything is paid in full, you can use the delivered content for your business.
- Raw footage and project files: {{raw_footage_lower}}.
- We may show the work in our portfolio and on our social media, unless you tell us in writing that you would rather we did not.

{{#retainer}}## 9. Retainer terms
- Minimum term: {{retainer_min_months}} months.
- After that, either side can end it with {{retainer_notice_days}} days’ written notice.
- {{#unused_visits_roll_over}}Unused visits roll over to the next month.{{/unused_visits_roll_over}}{{^unused_visits_roll_over}}Unused visits do not roll over to the next month.{{/unused_visits_roll_over}}

{{/retainer}}## {{#retainer}}10{{/retainer}}{{^retainer}}9{{/retainer}}. Ending the agreement early
Either side can end this agreement in writing{{#retainer}}, with the notice above{{/retainer}}. You pay for the work done up to that point, and we hand over what is finished and paid for.

## {{#retainer}}11{{/retainer}}{{^retainer}}10{{/retainer}}. Signatures
How we sign: {{signing_lower}}.

**For {{business_name}}**
Name: ______________________
Signature: __________________
Date: ______________________

**For Studiio**
Name: {{contact_person}}
Signature: __________________
Date: ______________________
