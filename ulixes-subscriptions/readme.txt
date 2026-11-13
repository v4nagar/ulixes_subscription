=== Ulixes Subscriptions ===
Contributors: v4nagar, Vishnu Kumar Nagar
Tags: woocommerce, subscriptions, recurring payments, memberships, billing
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Sell products and services with recurring payments, free trials, sign-up fees, renewals, and switching in WooCommerce.

== Description ==

**Ulixes Subscriptions** is a complete recurring billing and subscription management solution for WooCommerce, developed by **Vishnu Kumar Nagar**. It empowers WooCommerce stores to create and manage products with recurring payments — including physical and virtual subscriptions, variable subscription plans, free trials, one-time sign-up fees, automated and manual renewals, plan switching (upgrades/downgrades), and comprehensive subscriber self-service tools.

= Key Features =

* **Simple & Variable Subscriptions**: Create standalone simple subscriptions or variable subscriptions with multiple billing tiers (e.g., Monthly vs. Yearly).
* **Flexible Billing Schedules**: Bill customers daily, weekly, monthly, or yearly at intervals from every 1st to every 6th period, with optional expiration lengths.
* **Free Trials & Sign-Up Fees**: Charge an initial sign-up fee and/or offer a free trial period (in days, weeks, months, or years) before recurring billing begins.
* **Automatic & Manual Renewals**: Powered by WooCommerce Action Scheduler for reliable recurring payment processing, with full support for manual invoice renewals.
* **Upgrades, Downgrades & Crossgrades (Switching)**: Allow subscribers to switch between variable subscription variations or grouped subscriptions with configurable recurring price, sign-up fee, and length proration.
* **Purchase Limitations & Duplicate Prevention**: Limit customers to one active subscription (or one subscription of any status) per product while allowing seamless upgrades/downgrades between variations and early renewals.
* **Early Renewal & Resubscribe**: Let customers renew their active subscriptions early to extend their next payment date or easily resubscribe to expired/cancelled plans.
* **Synchronised Renewal Dates**: Align subscription renewals to a specific day of the week, day of the month, or annual date, with optional proration and sign-up grace periods.
* **Failed Payment Retry System**: Automatically retry failed recurring payments according to a configurable retry schedule before placing subscriptions on hold or cancelling.
* **Customer Self-Service (My Account)**: Dedicated **My Account > Subscriptions** dashboard where customers can view subscription details, toggle auto-renewal, suspend, reactivate, cancel, change addresses, update payment methods, renew early, or upgrade/downgrade plans.
* **Subscription Coupons**: Recurring percent, recurring fixed cart, recurring fixed product, and sign-up fee discount coupons with optional limited billing-cycle counts.
* **Drip Downloadable Content**: Control access to new downloadable files added to existing subscription products until the subscriber's next renewal payment.
* **14 Transactional Emails**: Complete suite of customizable admin and customer HTML/plain-text emails for renewals, switches, suspensions, expirations, payment retries, and pre-renewal reminders.
* **Analytics & Reports**: Built-in subscription revenue, retention, upcoming recurring revenue, and active subscriber reports under **WooCommerce > Subscriptions > Reports**.
* **Modern WooCommerce Compatibility**: 100% compatible with High-Performance Order Storage (HPOS / Custom Order Tables), WooCommerce Cart & Checkout Blocks (Store API), and the WooCommerce REST API (`/wp-json/wc/v3/subscriptions`).

= Author & Support =

* **Author**: Vishnu Kumar Nagar
* **Email**: v4.nagar@gmail.com
* **Website**: https://ulixes.in/

== Installation ==

1. Upload the `ulixes-subscriptions` folder to `/wp-content/plugins/`, or upload `ulixes-subscriptions.zip` via **Plugins > Add New Plugin > Upload Plugin** in WordPress Admin.
2. Activate **Ulixes Subscriptions** through the **Plugins** menu in WordPress (requires WooCommerce to be active).
3. Configure your subscription settings under **WooCommerce > Settings > Subscriptions**.
4. Create a product under **Products > Add New** and select **Simple subscription** or **Variable subscription** from the Product Data dropdown.
5. Manage all customer subscriptions under **WooCommerce > Subscriptions**.

== Frequently Asked Questions ==

= Can customers upgrade or downgrade between Monthly and Yearly plans? =
Yes. When **Allow Switching** is enabled under **WooCommerce > Settings > Subscriptions**, customers with an active variable subscription can select a different variation on the product page (or click **Upgrade or Downgrade** from **My Account > View Subscription**) to switch their existing subscription without creating a duplicate subscription.

= Can a customer buy the exact same active subscription plan twice? =
By default, subscription products are limited to one active subscription per customer. If a customer already has an active subscription to the same plan, duplicate purchase is prevented and they are offered options to **Renew Early** or **Upgrade / Downgrade** to a different variation. You can customize this per product under **Product Data > Advanced > Limit subscription**.

= Does Ulixes Subscriptions support WooCommerce HPOS and Checkout Blocks? =
Yes. Ulixes Subscriptions is fully compatible with WooCommerce High-Performance Order Storage (HPOS) and WooCommerce Cart & Checkout Blocks (`store-api`).

= How can I contact the author for support or custom gateway integration? =
You can reach the author, **Vishnu Kumar Nagar**, directly at **v4.nagar@gmail.com** or visit **https://ulixes.in/**.

== Screenshots ==

1. Subscription management list table under **WooCommerce > Subscriptions**.
2. Subscription edit screen with **Schedule** meta box, billing items, and **Related Orders**.
3. Simple and Variable Subscription product pricing, trial, sign-up fee, and synchronisation fields.
4. **WooCommerce > Settings > Subscriptions** configuration panel.
5. Customer **My Account > Subscriptions** self-service portal.

== Changelog ==

= 1.0.0 =
* Initial release by Vishnu Kumar Nagar (v4.nagar@gmail.com).
* Added Simple Subscription and Variable Subscription product types.
* Added HPOS & CPT data stores, Action Scheduler recurring renewals, and Failed Payment Retry engine.
* Added Subscription Switching (Upgrades/Downgrades), Early Renewal, Resubscribe, and Purchase Limiter.
* Added 14 transactional emails, REST API (`/wc/v3/subscriptions`), Cart/Checkout Blocks integration, and Admin Reports.

== Upgrade Notice ==

= 1.0.0 =
Initial release of Ulixes Subscriptions for WooCommerce.

