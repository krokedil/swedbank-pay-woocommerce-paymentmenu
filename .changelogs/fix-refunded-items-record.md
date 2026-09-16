Type: Fix
Needs Documentation: no

Fixed an issue where the record of already refunded items was stored with duplicated rows and inflated quantities, which made the quantities offered for a later refund of the same order wrong.
