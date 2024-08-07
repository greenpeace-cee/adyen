# Adyen Payment Processor

[![Run unit tests](https://github.com/greenpeace-cee/adyen/actions/workflows/unit-tests.yml/badge.svg)](https://github.com/greenpeace-cee/adyen/actions/workflows/unit-tests.yml)

CiviCRM payment processor for integration with [Adyen](https://www.adyen.com/).

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Installation

Learn more about installing CiviCRM extensions in the [CiviCRM Sysadmin Guide](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/).

This extension requires Payment Shared (https://lab.civicrm.org/extensions/mjwshared). Note that this dependency is fairly soft (except that it is specified in the info.xml) in that the only functionality of mjwshared that this uses is the webhook queuing, which is nice to have rather than strictly essential to the functioning.

## Setup

Login to adyen portal. Create API credentials and make sure you add the URL of your CiviCRM server to "Allow Origins".

In CiviCRM add a new payment processor with type "Adyen".
Configure the Merchant Account, X-API-Key.
Then you need to add a JSON-formatted configuration for the other parameters:
```json
{
  "clientKey": "test_XXX",
  "urlPrefix": "",
  "hmacKeys": {
    "0": "key1",
    "1": "key2"
  },
  "retryPolicy": [ "+1 day", ... ],
  "unmatchedContributionBehaviour": "create",
  "failedContributionStatus": "Failed",
  "invoicePrefix": "CiviCRM"
}
```

- The hmacKeys are used to validate webhooks.
- The client Key is used for submitting payments via CiviCRM.
- The URL prefix is only required for the live payment processor.
- The `retryPolicy` is an array of the following strings to determine when and if failed payment attempts should be retried.
   - `+<N> <period>` e.g. `+1 day` or `+2 weeks`: when to retry, relative to the last attempt
   - `"skip"` means do not re-attempt this payment, wait for next cycle.
   - `"fail"` means mark the recurring contribution Failed; no further attempts will be made now or in the future.
- `unmatchedContributionBehaviour` (optional) is used to change the behaviour when receiving webhooks for contributions that do not exist:
   - `create` (default) will create a new contribution (and find or create the corresponding contact)
   - `retry` will add the webhook back to the queue and retry processing at a later point; this is useful if payments are
     primarily collected outside of CiviCRM and sent to CiviCRM later on.
- `failedContributionStatus` (optional, defaults to "Failed") is the contribution status that is used when a payment failed
- `invoicePrefix` (optional, defaults to "CiviCRM") is the prefix used when generating invoice IDs

## Known issues

- The payment "dropin" for taking payments directly in CiviCRM is not fully implemented because it was not a client requirement.
Currently it loads with a fixed amount (EUR 10).

- The webhook checks do not work - it is supposed to authorize using the X-API-Key but returns 401 unauthorized when getting the list of webhooks.

- We currently *only* process the `AUTHORISATION` and `SETTLEMENT_REPORT` webhooks.
- In Adyen dashboard -> Webhooks there is a "Settings" button.
  Click on that to enable "Delayed Capture" notifications so that we can process "CAPTURE" notifications.
  Not sure if this will work!

## Reference

See https://docs.adyen.com/account/manage-payments

## Setup Adyen Webhook notifications

You have to setup the webhook manually for now (see Known Issues).

1. Add a "standard notification" with a CiviCRM webhook URL (eg. https://example.org/civicrm/payment/ipn/26) where 26 is your payment processor ID in CiviCRM.
2. Set "Merchant Accounts" to include only the merchant account you are interested in processing (setup a separate payment processor in CiviCRM for each merchant account).
3. Set "default events enabled".
4. Setup HMAC key and put in the JSON config per "Setup" above.
5. In additional settings->Card enable "Include Shopper Details" otherwise we will not be able to identify the contact when receiving an AUTHORISATION webhook.
