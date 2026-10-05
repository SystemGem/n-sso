# Learner journey

## 1. Registered customer (most common)

1. The learner logs in (or registers at checkout) and buys a product with **Grant N+ access**. It can be the same product Edwiser Bridge uses to enrol them in our Moodle course.
2. Payment succeeds and the order becomes **Processing** (or **Completed**; this is configurable).
3. The plugin queues a background job (Action Scheduler) for that order. The checkout is never slowed down or blocked by N+.
4. The job runs:
   - **Create User API.** The learner is created on N+, or N+ says `already_exists` and returns the existing ID. The N+ user ID is saved on the WordPress user, so later purchases skip this step.
   - **Subscription Assignment API.** This is called once per N+ line item. The N+ order ID is saved on the line item.
   - Each step adds an order note.
5. The **thank-you page** shows "Start learning on N+" with an **Access my N+ learning** button. The order email contains the same link.
6. The learner clicks the button and goes to `/?nplus-sso=launch` on our site:
   - The endpoint checks they are logged in and have an active N+ enrollment.
   - If the background job hasn't finished yet, the endpoint provisions the order right now.
   - It builds `https://learn.nplus.global/auto-login/?uid=…&timestamp=…&signature=…` with a fresh timestamp and redirects.
7. N+ validates the signature and signs the learner in. They see their N+ material.
8. Later, the learner can come back any time through **My Account > N+ Learning**, the view-order page, the email link, or any page with the `[nplus_sso_button]` shortcode.

## 2. Guest checkout

The steps are the same, but there is no WordPress user, so the N+ user ID is stored on the order. The thank-you page and email link include the order ID and the order's secret key (`&order_id=…&key=wc_order_…`), which is how WooCommerce itself authorises guest order pages. Turn this off with **Allow guest-checkout buyers to launch N+** if every buyer must have an account.

## 3. Learner buys a second N+ programme

The N+ user ID is reused, so Create User is not called again. Only the Subscription Assignment API is called, for the new item. N+ answers `isUserAlreadyEnrolled = 1` if they already had that campaign.

## 4. N+ is down or rejects a request

- The line item is marked **Failed** with the error. The order note and the WooCommerce log (`nplus-sso`) show the reason.
- Retries happen automatically after 5, 10, 20, 40 … minutes, up to the "Max automatic attempts" setting.
- If the learner clicks the button meanwhile, the endpoint tries again. If it still fails, they see "Your N+ access is being set up. Please try again in a few minutes."
- After the last attempt, an order note asks the admin to fix the cause and use **Order actions > Sync to N+**.

## 5. Unpaid orders

On-hold (bank transfer), pending and failed orders are **never** provisioned. The thank-you page says access will activate once payment is confirmed. When the admin marks the order Processing or Completed, provisioning runs.

## 6. Refund or cancellation

The order is flagged as revoked and the launch button disappears for it. If it was the learner's only N+ order, they lose launch access. **N+ has no cancellation API.** The order note lists the N+ order IDs to send to N+ support for cancellation. If the order is later set back to Completed, access is restored.

## 7. Someone tries to misuse a link

- Logged out on `/?nplus-sso=launch` → sent to the login page, then back to the launch.
- A link for someone else's order (registered buyer) → "This order belongs to a different account."
- A forged or wrong order key → 403, and nothing is signed.
- An old signed N+ URL → rejected by N+ because the timestamp has expired. We never store or email signed URLs.
