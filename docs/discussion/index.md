# Discussion

Discussion pages talk around the subject; they're useful background.

## The sorry state of contribution APIs.

We have, as of CiviCRM 5.52

- `ContributionRecur.create`: OK.

- ~~`Contribution.create`~~: not to be used for creating contributions;
  `Order.create` should be used instead. However, it can be used to *update* an
  existing contribution for things like transaction IDs, **but not for
  status**.
- `Order.create`: the 'new' way to create a Pending Contribution.

- `Payment.create`: the 'new' way to record a payment against a Contribution
  that handles the normal case when that payment *completes* the Contribution,
  triggering business logic (e.g. memberships). Contributions can have multiple
  payments, which get added up to compare against the total. They can be less
  than, equal to, or exceed the Contribution total(!). However, there is no way
  to create a failed payment, except by recording it as having zero value.

- `Contribution.repeattransaction`: the docblock says it’s a mess and it is. It
  says it is supposed to "Complete an existing (pending) transaction" but the
  body of its work is to do with *creating* a new contribution from a template
  or the last completed one.

- CiviContribute's process for a recur is typically: create a recur, create a
  pending linked contribution that hopefully gets completed by a successful
  payment later, then future payments are created from either the last
  Completed Contribution, or from a 'Template' Contribution if that exists.

- The [BAO's docblock](https://lab.civicrm.org/dev/core/-/blob/02fcadf400f294bd5ba12c33c0b4697ea21a5717/CRM/Contribute/BAO/Contribution.php#L2141) also laments this state, but offers direction for the way the APIs should be used.

- Here we have gone with the way things should be used, and will put in tests and guards around whether that's doing the right thing.


## Annoyances

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

How to record failed payment attempts?
<https://chat.civicrm.org/civicrm/pl/di9o7b8sifypu8iw3xmxxskzqr>
Note that the way we do it is to leave a failed Contribution instaed of failing the payment.
