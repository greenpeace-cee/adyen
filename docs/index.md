# Adyen payment processor integration.

This is a **limited** integration, with only the parts required by the client implemented.

Their use-case is:

1. Some process outside of CiviCRM (so not Civi's Contribution/Events/Membership forms) either:
   - takes a one-off payment
   - sets up a (should be) recurring payment, and takes the initial payment.

2. Adyen's webhook process sends info on this to Civi. The info includes a payment token which uniquely identifes the payment method via Adyen; it is not a unique payment or subscription ID.

3. Civi is responsible for taking repeat payments as and when they’re due.


## Rich’s notes

Card expiry date comes through on AUTHORISATION webhook under notificationItems.additionalData.expiryDate
<https://docs.adyen.com/api-explorer/#/Webhooks/v1/post/AUTHORISATION__reqParam_notificationItems-additionalData-expiryDate>

Also relevant

- capturePspReference
- recurring.firstPspReference Recurring first PSP reference.
- recurring.recurringDetailReference recurring detail reference.
- tokenTxVariant Payment method variant of the token/wallet payment method.
- A PSP reference [is defined as][1] *Every payment or modification request (such as a refund or a capture request) in Adyen's system has a globally unique 16-character string called the PSP reference associated with it. This string is alphanumerical.*

## Trying to figure out

- exactly what AUTHORISATION data to expect for one offs and regulars.

## AUTHORISATION events received in webhook payloads

- The event's `merchantReference` becomes the Contribution's `trxn_id`. (must be present)
- If the event is not a success, we ignore it.
- We look up the `trxn_id`; if found we’re done.
- We do a cheap getorcreate on contact; if the names match exactly and (if
  there is an email, the email matches), then we use that contact; otherwise we
  create a new one. Email is added as a billing email.
- A Pending Contribution is created with Api4 (not Order api - fixme), financial type Donation (fixme), payment instrument 'credit card' (fixme?)
   - source not used
   - fee_amount not used (available?)
   - recurring ??

Further thoughts:

- This uses MJWShared traits; I'd like to review some of the code patterns used
  there; there's no separation of concerns between the IPN object as
  representing the whole webhook (multiple events) and the IPN object working
  with a single event. e.g. we have some properties that relate to one thing
  and some to another; could lead to pollution between events for example.

- Also, this uses MJWShared to queue webhooks. This is really sensible and
  there's a nice UI, however the settlement extension also creates a db table
  (api4 entity) to store such notification items. Probably needs a
  conversation: do we need both (possibly)?

- I'm none the wiser on what recurring data I would see, or how it would be stored.

- I need a way to make payments and set up recurs that follows the same pattern as whatever GP are using, but on my test account.

- Hookable processing makes sense here, e.g. getorcreate

## Tasks

- set up tests on existing functionality
- research how iATS takes payments.

## Research: iATS

A scheduled job

- acquires a lock on `CRM_Core_Lock` to prevent parallel instances creating duplicate payments
- Various special inputs:
   - catchup mode (not normally set, but if so, it will use the `next_sched_contribution_date` as the date of the contribution, otherwise now.)
   - ignore memberships
   - `failure_count`
   - `cycle_day`
- limits query to active payment processors
- Requires `payment_token_id > 0` Optionally used to store a link to a payment token used for this recurring contribution. FK to civicrm_payment_token.id
   - payment token table links to contact, payment processor and provides a 255 varchar `token`, `email` address at time of creation (for forensics), billing names (first, middle, last), IP address, expiry date and masked account number.
- Finds CRs where `next_sched_contribution_date` < end of today, using php's timezone, and assuming the data in the database to match. Loops:
- Strategy: create the contribution record with status = 2 (= pending), try the payment, and update the status to 1 if successful
  also, advance the next scheduled payment before the payment attempt and pull it back if we know it fails.
- Looks for a template for creating a new contribution. 
- Checks existance of payment token referenced by CR, collects an error string if not found. Presumably here it should also check the expiry date.
- something special on `is_email_receipt`
- checks for a pending contribution linked to this CR that has a NULL trxn_id. It will recycle this record if found.
- Line items: if found on the template, adds skipLineItems =1 to CN.create call, and adds an api.line_item.create chain call with the template's line items.
- If error (payment token), write a failed contribution and continueloop
- Sets `membership_id` if there's a MembershipPayment record for the template contribution.
- So far so, good ... now use my utility function process_contribution_payment to create the pending contribution and try to get the money, and then do one of:
   - update the contribution to failed, leave as pending for server failure, complete the transaction,
   - or update a pending ach/eft with it's transaction id.
   - But first: advance the next collection date now so that in case of server failure on return from a payment request I don't try to take money again.
   - Save the current value to restore in case of payment failure (perhaps ...).
 - There's some hacks around whether to use repeattransaction, which is used for CR CNs where the CN is not yet saved.
 - If not using repeattransaction, it handles the CN.create call and also MembershipPayment.create, and finishes with CN.completetransaction 
 - Various hacks around core's resettting of data - Wow this is annoying.
 - (unclear: how contributions that end up still pending due to server failure are ever picked up)
 - uses `failure_count`
 - updates CR with `failure_count`, `next_sched_contribution_date`
 - creates an Activity (type 6) to record the attempt.
 - end of loop: release the lock.

 Thoughts:

- Locks: using a SQL lock seems sensible. Q. what happens to a lock if the php process crashes?
- It gathers all CRs where the `next_sched_contribution_date` is before the end of today. I suppose if a recur is very behind then 2 contributions may be due. In this situation (highly unlikely unless there's daily recurrings!) then the process would run one payment and then on next ('Daily') cron run would run the 2nd payment. Seems sensible.
- Failures: two types of failure are allowed for, server and payment.
- iATS may report that the card is stolen, or may say no further payments. In these cases the CR is updated to Pending (why not to Failed?)
- Server errors leave the CN as Pending; other errors will set it to Failed.
- The dancing around core is painful.

### How do I do CR processing in GC?

- if there's a pending CN (typically: just the first ever payment for a CR), then does a Payment.create call.
- otherwise: use CN.repeattransaction, then (hack copied from iATS) overwrite invoice_id, receive_date, payment_instrument_id using a CN.create call. Then Payment.create.
- SQL hack to set `CN.receive_date`.

In GC there's no chance of failure that we need to do anything about; the data we are sent is final.

### Annoyances

Core’s design for recording a one off contribution is:

- Order.create with CN data + line items (in a weird nested array),
- Payment.create
- IRL: some hacks to properly set things core didn't.

And for a recur where there's a pending:

- Custom CN.create calls to update it as needed.
- Payment.create
- IRL: some hacks to properly set things core didn't.

And for a recur where there’s not a pending:

- CN.repeattransaction
- IRL: some hacks to properly set things core didn't.
- Payment.create
- IRL: some hacks to properly set things core didn't.

### Adyen model for claiming payments

This could work similarly, given that the iATS process has been in production for a long time; it works.

1. Gather CRs that are due (In Progress, `next_sched_contribution_date`, active Adyen pp)
2. No pending CN? Use `CN.repeattransaction` to make one.
3. Attempt a payment via Adyen's API, record via `Payment.create`


  [1]: https://docs.adyen.com/get-started-with-adyen/payment-glossary#psp-reference

