# Tuma Payments for WooCommerce

Accept M-Pesa payments to any bank account through the Tuma Payments API in your WooCommerce store. Includes optional POS inventory sync to keep your online and physical store inventory in sync.

## Supported Payment Methods & Institutions

Tuma Payments Gateway supports all major banks, microfinance institutions, and SACCOs in Kenya, as well as mobile money platforms with **real-time direct fund settlement** to both **personal and business accounts**. Funds are settled instantly to your preferred account across all supported channels.

### Mobile Money
- **M-PESA** (Paybill Number / Buy Goods Till Number)
- **Airtel Money**

### Banks
- **Equity Bank**
- **Kenya Commercial Bank (KCB)**
- **Cooperative Bank of Kenya**
- **Diamond Trust Bank (DTB)**
- **NCBA**
- **Family Bank**
- **Stanbic Bank**
- **I&M Bank**
- **Access Bank**
- **Standard Chartered Bank**
- **ABSA**
- **SBM Bank Kenya**
- **National Bank**
- **Sidian Bank**

### Microfinance & Digital Banks
- **Loop**
- **KWFT**
- **Faulu Bank**

### SACCOs
- **Fortune Sacco**
- **K-Unity Sacco**
- And many more...

## Getting Started

### Step 1: Create Your Tuma Payments Account
1. Visit [https://merchant.tuma.co.ke](https://merchant.tuma.co.ke) and sign up for a merchant account
2. Complete the registration process and verify your account

### Step 2: Set Up Your Shop
1. Login to your Tuma Payments merchant dashboard
2. Navigate to **Shops** section and create a new shop
   
   ![Create Shop](assets/tm-create-shop.png)

### Step 3: Get Your API Credentials
1. Go to the **Developer** section in your dashboard
2. Copy your **Shop Email** and **API Key**
   
   ![Developer Section](assets/tm-developer.png)

## Installation

### Step 4: Download and Install Plugin
1. Download the plugin from [https://github.com/matatashadrack/tuma-mpesa-for-woocomerce/archive/refs/tags/v1.0.0.zip](https://github.com/matatashadrack/tuma-mpesa-for-woocomerce/archive/refs/tags/v1.0.0.zip)
2. Upload the plugin to your WordPress site:
   - Go to **Plugins > Add New > Upload Plugin**
   - Choose the downloaded zip file and click **Install Now**
3. Activate the plugin through the 'Plugins' menu in WordPress

### Step 5: Configure the Plugin
1. Go to **WooCommerce > Settings > Payments**
2. Find **Tuma Payments** and click **Configure**
   
   ![Configure Tuma Payments](assets/tm-configure-tuma.png)

3. Enter your credentials:
   - **Shop Email**: The email from your Developer section
   - **Shop API Key**: The API key from your Developer section
   
   ![Plugin Settings](assets/tm-wc-details.png)

4. Click **Test Connection** to verify your credentials
5. Enable the payment method and save settings

## POS Sync Setup (Optional)

If you use Tuma POS for your physical store, you can enable inventory and sales sync to keep both stores in sync.

### Enable POS Sync

1. Go to **WooCommerce > Settings > Payments > Tuma Payments**
2. Scroll down to the **POS Sync Settings** section
3. Configure the following options:

#### Enable POS Sync
- Check **Enable POS Sync** to sync online sales with your Tuma POS
- When enabled, each WooCommerce order is recorded as a sale in your POS
- Stock levels are managed by the POS system

#### Enable Product Sync
- Check **Enable Product Sync** to import products from your Tuma POS
- Products are synced automatically every hour
- Use the **Sync Products Now** button for immediate sync

### How POS Sync Works

1. **Product Sync**: Products from your Tuma POS are imported to WooCommerce with:
   - Product name, description, and price
   - SKU mapping for inventory tracking
   - Product images (if available)
   - Stock quantities
   - **Variable products** with all their variations (color, size, etc.)
   - Individual variant pricing and stock levels

2. **Sales Sync**: When a customer places an order:
   - The sale is sent to your Tuma POS
   - M-Pesa STK push is triggered for payment
   - Stock is deducted from POS inventory
   - Order status updates automatically on payment confirmation

### Product Sync Status

After enabling product sync, you can see the sync status in **Products > All Products**:
- A **POS Sync** column shows which products are linked to your Tuma POS
- Products display their Tuma Product ID for reference

## Notification Setup

### Real-time Payment Notifications
Get instant Payment alerts via WhatsApp, Telegram and Slack.

#### Configure Notifications
1. Navigate to **Notifications**: In your merchant dashboard, go to the "Notifications" tab

#### Configure Telegram:
1. Click "Setup Telegram Notifications"
2. Follow the bot setup instructions
3. Enter the verification code

#### Configure WhatsApp:
1. Enter your WhatsApp API token
2. Provide your WhatsApp phone number
3. Click "Activate WhatsApp Notifications"



## Features

### Payment Features
- **Real-time Payment Status**: Customers see live payment confirmation
- **STK Push Integration**: Seamless M-Pesa payment experience
- **Payment Retry**: Customers can resend STK push if needed
- **Receipt Display**: M-Pesa receipt numbers shown to customers
- **Order Management**: Automatic order status updates
- **Comprehensive Logging**: Detailed payment notes in order history

### POS Sync Features (Optional)
- **Inventory Sync**: Sync products from your Tuma POS to WooCommerce
- **Variable Products**: Full support for products with variations (size, color, etc.)
- **Sales Sync**: Online sales automatically recorded in your POS
- **Variant Stock Management**: Individual stock tracking for each product variation
- **Stock Management**: Unified stock levels across online and physical stores
- **Automatic Sync**: Hourly product sync keeps inventory up to date
- **Manual Sync**: One-click product sync from admin panel

### Product Variations Support
- **Variable Product Sync**: Products with variants in Tuma POS are synced as WooCommerce Variable Products
- **Attribute Mapping**: Variation attributes (Color, Size, etc.) are automatically created
- **Variant Stock**: Each variation maintains its own stock level
- **Variant Pricing**: Support for different prices per variation
- **SKU Mapping**: Variant SKUs are mapped for accurate inventory tracking

## Requirements

- WordPress 4.6+
- WooCommerce 3.5.0+
- PHP 7.0+
- Active Tuma Payments merchant account
- Valid Kenyan M-Pesa phone number for testing

## Troubleshooting

### Common Issues / FAQs

1. **"Connection Failed" Error**
   - Verify your Shop Email and API Key are correct
   - Ensure your Tuma Payments account is active and verified

2. **STK Push Not Received**
   - Check that the phone number is registered with M-Pesa
   - Ensure the phone number format is correct (254XXXXXXXXX)

3. **Payment Status Not Updating**
   - Check that your site has a valid SSL certificate
   - Verify the callback URL is accessible from the internet

4. **Product Sync Not Working**
   - Ensure **Enable Product Sync** is checked in settings
   - Verify your API credentials are correct
   - Check the error log for sync failures
   - Try clicking **Sync Products Now** for manual sync

5. **POS Sale Not Recording**
   - Ensure **Enable POS Sync** is checked in settings
   - Verify products have a valid Tuma Product ID (sync products first)
   - Check that the product SKU matches between WooCommerce and POS

## Support and Resources

### Documentation
- **API Documentation**: [https://github.com/matatashadrack/tuma-mpesa-stk-push](https://github.com/matatashadrack/tuma-mpesa-stk-push)
- **Merchant Portal**: [https://merchant.tuma.co.ke](https://merchant.tuma.co.ke)

### Support Channels
- **Email**: support@tuma.co.ke
- **Phone**: +254722854082 / +254733854082
- **Twitter**: [@tumaonline](https://twitter.com/tumaonline)
- **Business Hours**: Monday - Friday, 8:00 AM - 6:00 PM EAT

### Getting Help

For technical support and questions:
- Email: support@tuma.co.ke
- Documentation: [https://merchant.tuma.co.ke/docs](https://merchant.tuma.co.ke/docs)

## License

This plugin is licensed under the GPL v3 or later.
