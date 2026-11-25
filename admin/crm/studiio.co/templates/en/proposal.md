%% Proposal (spec 5.2): the client's goal in their words, what we do, deliverables, what is not
%% included, timeline, price, how to start. Four to six pages at most.
%% Lines starting with %% are notes for us and never reach the client. Placeholders: see ../../README.md.
# {{project_title}}

## Your goal
{{#client_goal}}**In your words:** {{client_goal}}{{/client_goal}}
{{#success}}**Worth it when:** {{success}}{{/success}}
{{^client_goal}}{{^success}}We will confirm the goal together before we start, so everything we make points at it.{{/success}}{{/client_goal}}

## What we will do
{{#scope}}{{scope}}{{/scope}}{{^scope}}{{track_about}}.{{/scope}}
{{#retainer}}Every month we plan the content with you, shoot, edit and deliver. You approve the plan before we shoot.{{/retainer}}
{{#production}}We handle the idea, the script or shot list, the shoot, lighting and the edit. You approve the script or shot list before the shoot day.{{/production}}
{{#photography}}We agree the shot list with you, shoot, and send a selection for you to pick your favourites. Then we retouch the picks.{{/photography}}

## Deliverables
{{#deliverables_list}}{{deliverables_list}}{{/deliverables_list}}{{^deliverables_list}}We confirm the exact list with you before we start.{{/deliverables_list}}

## Not included
{{#exclusions_list}}{{exclusions_list}}{{/exclusions_list}}{{^exclusions_list}}- Models, locations, props and music licences, unless listed above
- Ad spend
- Raw footage and project files ({{raw_footage_lower}}){{/exclusions_list}}

## Timeline
{{#start_date}}- Start: {{start_date}}{{/start_date}}
{{#first_shoot}}- First shoot: {{first_shoot}}{{/first_shoot}}
{{#draft_date}}- First draft: {{draft_date}}{{/draft_date}}
{{#delivery_date}}- Final delivery: {{delivery_date}}{{/delivery_date}}
{{#retainer}}- Then every month: plan, shoot, edit, deliver. Minimum {{retainer_min_months}} months.{{/retainer}}
{{^start_date}}{{^first_shoot}}{{^delivery_date}}{{^retainer}}We lock the dates together at the kickoff call.{{/retainer}}{{/delivery_date}}{{/first_shoot}}{{/start_date}}
Dates move if feedback or materials arrive late.

## Price
{{#price}}**{{price}}**{{#retainer}} per month{{/retainer}}, in {{currency}}. {{payment_schedule}}.{{/price}}{{^price}}Price to be confirmed in the quotation.{{/price}}
{{revision_rounds}} rounds of revisions are included per deliverable.

## How to start
1. Sign the agreement.
2. {{#retainer}}Pay the first month{{#price}} ({{price}}){{/price}}.{{/retainer}}{{^retainer}}Pay the deposit{{#deposit_amount}} of {{deposit_amount}}{{/deposit_amount}}.{{/retainer}}
3. Fill in the checklist we send you.

Then we book the first shoot.
