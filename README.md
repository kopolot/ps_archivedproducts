# ps_archivedproducts

PrestaShop module for managing **archived / discontinued products** without breaking SEO. Compatible with **PrestaShop 1.7 and 8**.

## Problem

When you deactivate a product in PrestaShop, the product URL typically returns a 404 or redirect. That hurts SEO when you want to keep older product pages online for people searching for legacy models, while still preventing orders.

## Solution

This module:

- Keeps the product page accessible (HTTP 200) when a product is deactivated
- Hides archived products from catalog, search, and listings
- Blocks add to cart on archived product pages
- Shows a clear **archived product** banner on the product page
- Optionally hides prices on archived products
- Provides a one-click migration for existing inactive products

## Requirements

- PrestaShop 1.7.1+ or 8.0+

## Installation

1. Copy the `ps_archivedproducts` folder to your `modules/` directory
2. Install the module from **Modules > Module Manager**
3. Open module configuration and adjust settings if needed
4. Use **Update inactive products** to fix already deactivated products

## Configuration

| Setting | Description |
|---------|-------------|
| Auto-archive on deactivation | Automatically archives products when they are disabled in back office |
| Show archived banner | Displays a notice on archived product pages |
| Hide prices on archived products | Hides price information on archived pages |
| Archived product message | Custom multilingual message for the banner |

## How it works

When you **deactivate** a product in back office, the module archives it using **soft archive** (no override, works on PS 1.7 and 8):

| Field | Value | Effect |
|-------|-------|--------|
| `active` | **1** | Page stays accessible (HTTP 200) |
| `visibility` | **none** | Hidden from catalog, search, listings |
| `available_for_order` | **0** | Cannot be added to cart |

The product is also marked in the module table `ps_archivedproducts`.

To **restore** a product to sale, edit it and set visibility back to "Everywhere" **and** enable ordering — the module removes the archived flag automatically.

Products with an explicit SEO redirect (301/302 to another product/category) are not auto-archived.

## License

AFL-3.0

## Author

[kopolot](https://github.com/kopolot)
