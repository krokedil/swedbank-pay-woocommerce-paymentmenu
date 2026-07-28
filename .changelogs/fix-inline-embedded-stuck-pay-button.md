Type: Fix
Needs Documentation: no

Fixed an issue in the inline embedded checkout where the Pay button could be left showing a pending state, preventing the customer from retrying after a checkout validation error. WooCommerce replaces the whole payment section whenever the checkout is updated, which detached the element the payment menu was rendered into, and the payment menu was never rebuilt against the new one. On a return from a completed payment the checkout form was also left flagged as processing, which made WooCommerce silently discard any further attempt to place the order. The payment menu is now rebuilt when the payment section is replaced, and a payment attempt is always released when the order cannot be placed.
