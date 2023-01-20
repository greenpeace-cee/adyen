# Adyen Payment Processor Integration.

This is a **limited** integration, with only the parts required by the client implemented.

The functions implemented are:

- Automatically attempt to claim recurring contributions from Adyen where a ContributionRecur and PaymentToken have been set up.

- Process payment webhooks for the purposes of:
   - adding Contributions that do not already exist, but have been AUTHORISED by Adyen.
   - updating/creating related PaymentToken entities, where the Contribution belongs to a ContributionRecur, so that admins can see when a card expires from within Civi.

- Processing the settlement report webhook, to make sure everything lines up.

## Client use-case background

The client use-case for the first of these is: that some process outside of CiviCRM (so not Civi's Contribution/Events/Membership forms) either:

- takes a one-off payment
- sets up a recurring payment, and takes the initial payment.

This process also includes setting up Contacts, PaymentToken, Contribution and ContributionRecur entities in CiviCRM, with little more to do.

**This extension** is responsible for taking repeat payments as and when they’re due.

## Random useful references.

- Card expiry date comes through on `AUTHORISATION` webhook under `notificationItems.additionalData.expiryDate`
   <https://docs.adyen.com/api-explorer/#/Webhooks/v1/post/AUTHORISATION__reqParam_notificationItems-additionalData-expiryDate>

- A PSP reference [is defined as][1] *Every payment or modification request (such as a refund or a capture request) in Adyen's system has a globally unique 16-character string called the PSP reference associated with it. This string is alphanumerical.*


  [1]: https://docs.adyen.com/get-started-with-adyen/payment-glossary#psp-reference

