%% Kickoff agenda (spec 5.7), 30 minutes. For the call itself; the recap goes out after it (messages.json, kickoff_recap).
# Kickoff, {{project_title}}
30 minutes{{#kickoff_date}}, {{kickoff_date}}{{/kickoff_date}}.

## 1. The goal in one sentence (5 min)
{{#client_goal}}From the intake: {{client_goal}}{{/client_goal}}{{#success}} Worth it when: {{success}}{{/success}}

## 2. Scope, and what is not included (5 min)
{{#deliverables_list}}{{deliverables_list}}{{/deliverables_list}}
{{#exclusions_list}}**Not included:**
{{exclusions_list}}{{/exclusions_list}}

## 3. References and direction (10 min)
{{#references}}{{references}}{{/references}}{{^references}}Go through three accounts or videos they love.{{/references}}
{{#no_go}}**Not wanted:** {{no_go}}{{/no_go}}

## 4. Lock the dates (5 min)
- First shoot: {{#first_shoot}}{{first_shoot}}{{/first_shoot}}{{^first_shoot}}______{{/first_shoot}}
- First draft: {{#draft_date}}{{draft_date}}{{/draft_date}}{{^draft_date}}______{{/draft_date}}
- Delivery: {{#delivery_date}}{{delivery_date}}{{/delivery_date}}{{^delivery_date}}______{{/delivery_date}}

## 5. Approver, channel, feedback rules (3 min)
Approver: {{#approver}}{{approver}}{{/approver}}{{^approver}}______{{/approver}}. Channel: {{channel_label}}. {{revision_rounds}} rounds per deliverable, feedback within {{feedback_days}} working days.

## 6. Open checklist items (2 min)
{{#checklist_open_list}}{{checklist_open_list}}{{/checklist_open_list}}{{^checklist_open_list}}Nothing open.{{/checklist_open_list}}
