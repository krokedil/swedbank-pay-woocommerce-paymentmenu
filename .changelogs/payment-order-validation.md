Type: Fix
Needs Documentation: no

Seamless Menu no longer reuses a payment order that has already paid for another order. An order whose payment amount does not match the order total, or whose payment already belongs to another order, is now set to on-hold instead of being marked as paid.
