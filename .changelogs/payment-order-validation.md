Type: Fix
Needs Documentation: no

Seamless Menu no longer reuses a payment order that has already paid for another order, or a card verification for an order with an amount due. An order whose payment does not cover the order total, or whose payment already belongs to another order, is now set to on-hold instead of being marked as paid.
