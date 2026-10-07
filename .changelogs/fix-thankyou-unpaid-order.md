Type: Fix
Needs Documentation: no

Fixed an issue where an order was marked as paid when its order received page was opened, even if the customer had not paid. The order is now only marked as paid once Swedbank Pay has confirmed the payment.
