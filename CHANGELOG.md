# Changelog

## 0.1.1 - 2026-06-02

- Fix POS store-credit balance deductions when WCPOS sends signed or zero-value coupon line discounts to StoreApps Smart Coupons.
- Add guarded inference for POS orders where the store-credit usage is present on the order totals rather than the coupon line.
- Avoid over-deducting ambiguous mixed-coupon orders and POS orders with negative fee/manager discount lines.
- Expand regression coverage for StoreApps balance updates, mixed coupons, tax mode differences, zero-value coupon lines, and negative fees.

## 0.1.0 - 2026-05-20

- Initial StoreApps Smart Coupons compatibility layer for WCPOS.
