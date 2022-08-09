<h2>Adyen test checkout</h2>

{if $error}
    <div class="error crm-messages crm-error">
        {$error}
    </div>
{else}
<script src="https://checkoutshopper-test.adyen.com/checkoutshopper/sdk/5.19.0/adyen.js"
     integrity="sha384-F010SDWCbQuxSr7q3bqApTK7ZfYkR3UzzivdE/eeqeVFBqY447/L2aqNuHCOzUyv"
     crossorigin="anonymous"></script>

<link rel="stylesheet"
     href="https://checkoutshopper-test.adyen.com/checkoutshopper/sdk/5.19.0/adyen.css"
     integrity="sha384-YT51WAYU0K37yaa6mxuCgtUZlGnzq5lJUgwU+kGg1OBGaS7xoPZTqa3rhrxTv8wj"
     crossorigin="anonymous" />

<p>Make a test payment of EUR 1.01 from your primary email <em>{$email|escape}</em></p>


<div id="dropin-container"></div>



<script>
adyenSession = {$adyenSession};
{literal}
console.log({adyenSession});

document.addEventListener('DOMContentLoaded', () => {

    const configuration = {
      environment: 'test', // Change to 'live' for the live environment.
      clientKey: adyenSession.clientKey,
      analytics: { enabled: false },
      session: {
        id: adyenSession.id,
      },
      onPaymentCompleted: (result, component) => {
          console.info(result, component);
      },
      onError: (error, component) => {
          console.error(error.name, error.message, error.stack, component);
      },
      // Any payment method specific configuration. Find the configuration specific to each payment method:  https://docs.adyen.com/payment-methods
      // For example, this is 3D Secure configuration for cards:
      paymentMethodsConfiguration: {
        card: {
          hasHolderName: true,
          holderNameRequired: true,
          billingAddressRequired: true
        }
      }
    };

    async function bootInitial() {
        console.log({configuration});
        configuration.session.sessionData = adyenSession.sessionData;

        // Create an instance of AdyenCheckout using the configuration object.
        const checkout = await AdyenCheckout(configuration);

        // Create an instance of Drop-in and mount it to the container you created.
        const dropinComponent = checkout.create('dropin').mount('#dropin-container');
    }

    async function bootRedirectReturn() {
        // Create an instance of AdyenCheckout to handle the shopper returning to your website.
        // Configure the instance with the sessionId you extracted from the returnUrl.
        const checkout = await AdyenCheckout(configuration);
        // Submit the redirectResult value you extracted from the returnUrl.
        checkout.submitDetails({ details: { redirectResult: adyenSession.redirectResult } });
    }

    if (!adyenSession.redirectResult) {
        bootInitial();
    }
    else {
        bootRedirectReturn();
    }

});
</script>
{/literal}


{/if}

