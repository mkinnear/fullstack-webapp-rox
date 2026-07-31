<?php

/**
 * Creates a Stripe Checkout Session for a subscription and returns the
 * hosted checkout URL to redirect the browser to. Card details are typed
 * directly into Stripe's own page -- they never touch our server or
 * database, which is what keeps us out of full PCI-DSS scope.
 *
 * Requires the tier to have a stripe_price_id set (create the product/price
 * once in the Stripe Dashboard, then store its price ID on the tier row).
 */
function createStripeCheckoutSession(array $user, array $tier): array {
    $secretKey = getenv('STRIPE_SECRET_KEY');
    $frontendUrl = getenv('FRONTEND_URL');

    if (!$secretKey) {
        return ['error' => 'Payments are not configured yet (STRIPE_SECRET_KEY missing).'];
    }
    if (!$tier['stripe_price_id']) {
        return ['error' => 'This tier has no Stripe price configured yet.'];
    }

    $params = [
        'mode' => 'subscription',
        'line_items' => [[
            'price' => $tier['stripe_price_id'],
            'quantity' => 1,
        ]],
        'client_reference_id' => (string) $user['id'],
        'customer_email' => $user['email'],
        'success_url' => $frontendUrl . '/dashboard.html?checkout=success',
        'cancel_url' => $frontendUrl . '/dashboard.html?checkout=cancelled',
        'metadata' => ['user_id' => (string) $user['id'], 'tier_slug' => $tier['slug']],
    ];

    $response = stripeRequest('POST', '/v1/checkout/sessions', $params, $secretKey);
    if (isset($response['error'])) {
        return ['error' => $response['error']['message'] ?? 'Stripe request failed.'];
    }
    return ['url' => $response['url'] ?? null, 'id' => $response['id'] ?? null];
}

/**
 * Creates a Stripe Billing Portal session -- a hosted page where the
 * customer can update payment methods, view invoices, or cancel. We never
 * build this UI ourselves, same reasoning as checkout: Stripe owns
 * anything involving live payment method data.
 */
function createBillingPortalSession(string $stripeCustomerId): array {
    $secretKey = getenv('STRIPE_SECRET_KEY');
    $frontendUrl = getenv('FRONTEND_URL');
    if (!$secretKey) {
        return ['error' => 'Payments are not configured yet.'];
    }
    $response = stripeRequest('POST', '/v1/billing_portal/sessions', [
        'customer' => $stripeCustomerId,
        'return_url' => $frontendUrl . '/',
    ], $secretKey);
    if (isset($response['error'])) {
        return ['error' => $response['error']['message'] ?? 'Stripe request failed.'];
    }
    return ['url' => $response['url'] ?? null];
}

/** Pauses billing on a subscription without cancelling it outright. */
function pauseStripeSubscription(string $subscriptionId): array {
    $secretKey = getenv('STRIPE_SECRET_KEY');
    if (!$secretKey) return ['error' => 'Payments are not configured yet.'];
    $response = stripeRequest('POST', "/v1/subscriptions/$subscriptionId", [
        'pause_collection' => ['behavior' => 'void'],
    ], $secretKey);
    return isset($response['error']) ? ['error' => $response['error']['message'] ?? 'Stripe request failed.'] : ['ok' => true];
}

/** Resumes a previously paused subscription. */
function resumeStripeSubscription(string $subscriptionId): array {
    $secretKey = getenv('STRIPE_SECRET_KEY');
    if (!$secretKey) return ['error' => 'Payments are not configured yet.'];
    $response = stripeRequest('POST', "/v1/subscriptions/$subscriptionId", [
        'pause_collection' => '',
    ], $secretKey);
    return isset($response['error']) ? ['error' => $response['error']['message'] ?? 'Stripe request failed.'] : ['ok' => true];
}

/** Cancels a subscription outright -- used when an account is deleted, so nobody keeps getting billed. */
function cancelStripeSubscription(string $subscriptionId): array {
    $secretKey = getenv('STRIPE_SECRET_KEY');
    if (!$secretKey) return ['error' => 'Payments are not configured yet.'];
    $response = stripeRequest('DELETE', "/v1/subscriptions/$subscriptionId", [], $secretKey);
    return isset($response['error']) ? ['error' => $response['error']['message'] ?? 'Stripe request failed.'] : ['ok' => true];
}

/** Minimal Stripe REST client using cURL and application/x-www-form-urlencoded (Stripe's expected format). */
function stripeRequest(string $method, string $path, array $params, string $secretKey): array {
    $ch = curl_init('https://api.stripe.com' . $path);
    $body = http_build_query(flattenForStripe($params));

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $secretKey,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);

    return json_decode($raw, true) ?? ['error' => ['message' => 'Invalid response from Stripe']];
}

/** Stripe's form encoding needs bracket notation for nested arrays. */
function flattenForStripe(array $params, string $prefix = ''): array {
    $flat = [];
    foreach ($params as $key => $value) {
        $paramKey = $prefix === '' ? $key : "$prefix[$key]";
        if (is_array($value)) {
            $isList = array_is_list($value);
            foreach ($value as $k => $v) {
                $subKey = $isList ? $paramKey . '[]' : $paramKey . "[$k]";
                if (is_array($v)) {
                    $flat += flattenForStripe($v, rtrim($subKey, '[]'));
                } else {
                    $flat[$subKey] = $v;
                }
            }
        } else {
            $flat[$paramKey] = $value;
        }
    }
    return $flat;
}

/**
 * Verifies a Stripe webhook signature per Stripe's documented scheme:
 * HMAC-SHA256 of "{timestamp}.{payload}" using the webhook signing secret,
 * compared to the v1 signature(s) in the Stripe-Signature header.
 * Rejects timestamps older than 5 minutes to prevent replay attacks.
 */
function verifyStripeWebhookSignature(string $payload, ?string $sigHeader, string $secret): bool {
    if (!$sigHeader) return false;

    $parts = [];
    foreach (explode(',', $sigHeader) as $pair) {
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, null);
        $parts[$k][] = $v;
    }
    $timestamp = $parts['t'][0] ?? null;
    $signatures = $parts['v1'] ?? [];
    if (!$timestamp || empty($signatures)) return false;

    if (abs(time() - (int) $timestamp) > 300) return false; // reject stale/replayed webhooks

    $expected = hash_hmac('sha256', "$timestamp.$payload", $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}