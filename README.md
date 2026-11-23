# Partial Refund Desk for WooCommerce

**What to try.** Open the manual refund screen in a disposable store to review the lines, taxes and total before confirming.

[![WordPress and WooCommerce integration checks](https://github.com/aa71200/woo-partial-refund-desk/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/aa71200/woo-partial-refund-desk/actions/workflows/checks.yml)

A manual refund needs to account for discounts, tax and any refunds already recorded on the order. This CodexaWP sample previews the remaining amount, then records the confirmed refund through the WooCommerce API.

## Interface preview

<p align="center">
  <a href="docs/refund-preview.png"><img src="docs/refund-preview.png" width="900" alt="Partial refund preview with selected order items, subtotal, tax, and total"></a>
  <br>
  <em>Manual refund preview · two selected items, $19.80 including tax</em>
</p>

## Try it

Open **WooCommerce → Partial Refund Desk** as a shop manager, load a test order by ID, enter a quantity, and preview the amount. Confirming records a manual refund. Create and use sample orders in a disposable store.

## Verification

Activate this plugin on a disposable WordPress site with WooCommerce active and `WP_ENVIRONMENT_TYPE` set to `local`. Set `CODEXAWP_TEST_WP_LOAD` to the absolute path to that site's `wp-load.php`, then run `php tests/check.php` from this repository.

The fixture covers discounted taxable lines, a same-total change in selected lines, concurrent confirmation, and a native refund created after preview. The signed review is bound to the order, administrator, currency, lines and tax, and is valid for 10 minutes for a new operation. The order lock covers confirmations through this tool. It records manual refunds and does not send a payment or restock items.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
