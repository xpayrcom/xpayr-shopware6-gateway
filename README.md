<div align="center">
  <a href="https://xpayr.com">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://xpayr.com/public/images/xpayr-logo-white.png" />
      <source media="(prefers-color-scheme: light)" srcset="https://xpayr.com/public/images/xpayr-logo-black.png" />
      <img src="https://xpayr.com/public/images/xpayr-logo-white.png" alt="XPayr Official Logo" width="220" />
    </picture>
  </a>
  <p>
    <a href="https://xpayr.com"><img src="https://xpayr.com/partner-badge.php?type=powered_by&theme=dark" alt="Powered by XPayr" height="28" /></a>
    <a href="https://mcp.xpayr.com"><img src="https://img.shields.io/badge/MCP_Portal-mcp.xpayr.com-029bfd?style=for-the-badge" alt="XPayr MCP Portal" height="28" /></a>
    <a href="https://docs.xpayr.com"><img src="https://img.shields.io/badge/Docs-docs.xpayr.com-10b981?style=for-the-badge" alt="XPayr Docs" height="28" /></a>
    <a href="https://www.npmjs.com/package/@xpayr/mcp-server"><img src="https://img.shields.io/npm/v/@xpayr/mcp-server?style=for-the-badge&color=cb3837&label=MCP%20NPM" alt="NPM @xpayr/mcp-server" height="28" /></a>
  </p>
  <p>
    <a href="https://xpayr.com"><b>Official Website</b></a> •
    <a href="https://mcp.xpayr.com"><b>AI &amp; MCP Portal (21 Tools)</b></a> •
    <a href="https://docs.xpayr.com"><b>Developer Docs</b></a> •
    <a href="https://xpayr.com/developers"><b>Developer Hub</b></a> •
    <a href="https://xpayr.com/tools/crypto-payment-fee-calculator"><b>0.5% Fee Calculator</b></a>
  </p>
</div>

---

# XPayr Crypto Payment Gateway for Shopware 6

Non-custodial crypto checkout plugin for Shopware 6 stores.

## Features
- Hosted checkout via XPayr (`/pay/ps_xxx`)
- Creates payment session from Shopware order total
- Redirects customer to XPayr checkout after order placement
- Webhook callback with `X-XPayr-Signature` verification
- Order transaction state mapping for `completed / failed / expired / pending`
- Configurable blockchain network and settlement currency via admin dropdown

## Requirements
- Shopware 6.7+
- PHP 8.1+
- cURL extension

## Installation

### Via ZIP Upload
1. Download or create the plugin ZIP from this directory.
2. In Shopware Admin, go to **Extensions > My Extensions**.
3. Click **Upload Extension** and upload the ZIP file.
4. Click **Install** and then **Activate**.

### Via Composer (Development)
```bash
composer require xpayr/shopware6-payment-gateway
bin/console plugin:refresh
bin/console plugin:install --activate XPayrPlugin
bin/console cache:clear
```

## Configuration
After activation, go to **Extensions > My Extensions > XPayr Crypto Payment Gateway > Configure**:

- **API Base URL**: `https://xpayr.com/api/v1` (default)
- **Secret API Key**: Your XPayr secret key (`sk_test_...` or `sk_live_...`)
- **Blockchain Network**: Select from dropdown (BSC, Ethereum, Polygon, Base, etc.)
- **Settlement Currency**: Select from dropdown (USDC, USDT, DAI, ETH, etc.)
- **Webhook Secret**: From XPayr panel webhook settings
- **Debug Logging**: Enable to log API/webhook events

## Webhook
Set callback URL in XPayr dashboard:

```
https://<your-shop-domain>/xpayr/webhook
```

Supported events:
- `payment.completed`
- `payment.confirmed`
- `payment.failed`
- `payment.expired`
- `payment.pending`
- `payment.processing`

## Plugin Structure
```
shopware_xpayr/
├── composer.json
└── src/
    ├── XPayrPlugin.php
    ├── Service/
    │   └── XPayrPayment.php
    ├── Storefront/
    │   └── Controller/
    │       └── WebhookController.php
    └── Resources/
        └── config/
            ├── config.xml
            ├── services.xml
            ├── routes.xml
            └── plugin.png
```

## Notes
- This integration is non-custodial: funds are routed by on-chain payment flow.
- The plugin uses Shopware's `AsynchronousPaymentHandlerInterface` for proper order flow.
- Transaction states are managed via `OrderTransactionStateHandler`.
