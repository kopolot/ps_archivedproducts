# ps_archivedproducts

PrestaShop 8 module for managing **archived / discontinued products** without breaking SEO.

## Problem

When you deactivate a product in PrestaShop, the product URL typically returns a 404 or redirect. That hurts SEO when you want to keep older product pages online for people searching for legacy models, while still preventing orders.

## Solution

This module:

- Sets the product redirect type to **Displayed product page (HTTP 200)** when a product is deactivated
- Keeps the product page accessible with full content for SEO
- Blocks add to cart automatically (PrestaShop core behavior for inactive products)
- Shows a clear **archived product** banner on the product page
- Optionally hides prices on archived products
- Provides a one-click migration for existing inactive products

## Requirements

- PrestaShop 8.0+

## Installation

1. Copy the `ps_archivedproducts` folder to your `modules/` directory
2. Install the module from **Modules > Module Manager**
3. Open module configuration and adjust settings if needed
4. Use **Update inactive products** to fix already deactivated products

## Configuration

| Setting | Description |
|---------|-------------|
| Auto-set redirect on deactivation | Automatically applies HTTP 200 redirect when a product is disabled |
| Show archived banner | Displays a notice on archived product pages |
| Hide prices on archived products | Hides price information on archived pages |
| Archived product message | Custom multilingual message for the banner |

## How it works

PrestaShop already supports `redirect_type = 200-displayed` for inactive products. This module automates that workflow and improves the customer experience with a visible archived notice.

When a product is reactivated, the redirect type is reset to default.

## License

AFL-3.0

## Author

[kopolot](https://github.com/kopolot)
