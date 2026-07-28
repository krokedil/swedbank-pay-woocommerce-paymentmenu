Type: Feature
Needs Documentation: yes

Added support for asynchronous reversals. Payment methods that process refunds asynchronously (such as Banklink in the Baltics) answer the reversal request with HTTP 202 Accepted and report the outcome in a later callback. The refund is now registered in WooCommerce immediately and marked as awaiting confirmation. When the callback arrives, a confirmed reversal is processed as usual, while a rejected reversal puts the order on hold so the merchant can review it. No other payment action can be performed on the order while a reversal is awaiting confirmation. Should the callback never arrive, the refund is rechecked on a schedule for up to three days, after which the order is put on hold for manual verification.
