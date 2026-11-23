# Paymob Payouts for PHP & Laravel

Send money from your **Paymob Payouts** balance to Egyptian **mobile wallets** (Vodafone Cash, Etisalat Cash, Orange Cash, bank wallets), **bank cards**, **bank accounts** (instant transfer) and **Egypt Post** — with the safety checks a real payout system needs.

- **Plain PHP client** (PHP 7.4 – 8.3, any framework): log in, send a payout, check its status, read your balance.
- **Laravel add-on** (optional): a `paymob_payouts` table, **duplicate-payout protection**, events, and a command that settles timed-out payouts.

> **Unofficial package.** It is not made or endorsed by Paymob. "Paymob" is a trademark of its owner. Endpoints follow Paymob's official [Payouts Postman collection](https://github.com/PaymobAccept/API-Postman-Collections) and [Payouts docs](https://developers.paymob.com/paymob-docs/developers/payouts-apis/cashin-api/vodafone-cashin). Test on Paymob **staging** before going live.

---

## Why not just call the API?

Sending money is easy. Not sending it **twice** is the hard part.

| Situation | What this package does |
|---|---|
| Paymob times out or returns a 5xx | Result is **`unknown`**, never "failed" — so nobody presses "send" again while the money may already have left. |
| The same payout is triggered twice (double click, retry, two workers) | Laravel `PayoutManager` refuses a second payout for the same **reference** while one is successful, pending, unknown or in progress. |
| The server crashes mid-payout | A record is written **before** Paymob is called; the `reconcile` command finds out what happened. |
| Each payout needs to be traceable | Every request carries a unique `client_reference_id` you can look up at Paymob. |
| Access tokens expire every 60 minutes | Tokens are cached, refreshed, and renewed automatically on a 401. |
| Logs | Phone, card and national-id numbers are masked (`010****4567`). |

---

## Installation

```bash
composer require omarraafat/paymob-payouts
```

You need **Paymob Payouts** credentials (client id, client secret, username, password). These are different from Paymob Accept (online payments) keys — ask your Paymob account manager.

---

## Plain PHP

```php
use OmarRaafat\PaymobPayouts\Config;
use OmarRaafat\PaymobPayouts\DisbursementRequest;
use OmarRaafat\PaymobPayouts\PaymobPayouts;
use OmarRaafat\PaymobPayouts\Token\FileTokenStore;

$paymob = new PaymobPayouts(
    Config::fromArray([
        'client_id'     => getenv('PAYMOB_PAYOUTS_CLIENT_ID'),
        'client_secret' => getenv('PAYMOB_PAYOUTS_CLIENT_SECRET'),
        'username'      => getenv('PAYMOB_PAYOUTS_USERNAME'),
        'password'      => getenv('PAYMOB_PAYOUTS_PASSWORD'),
        'environment'   => 'staging',            // 'production' for real money
    ]),
    new FileTokenStore(__DIR__ . '/storage/paymob') // share the token between requests
);

$request = DisbursementRequest::vodafone('01001234567', 250.00);

// Save $request->clientReferenceId() in your database BEFORE sending.
$result = $paymob->disburse($request);

if ($result->isSuccessful()) {
    // Money sent. $result->transactionId()
} elseif ($result->isFailed()) {
    // Refused, nothing sent. $result->statusDescription() says why. Safe to fix and retry.
} else {
    // Pending or unknown: DO NOT send again. Check later:
    $later = $paymob->transactionStatus($result->transactionId(), $request->clientReferenceId());
}
```

### Payout types

```php
DisbursementRequest::vodafone('01001234567', 100);
DisbursementRequest::etisalat('01101234567', 100);
DisbursementRequest::orange('01201234567', 100, 'Ahmed Ali');          // full name required
DisbursementRequest::bankWallet('01001234567', 100, 'Ahmed Ali');      // full name required
DisbursementRequest::bankCard('1111222233334444', 'CIB', 'Ahmed Ali', 1500, 'cash_transfer');
DisbursementRequest::instantBank('EG380019000500000000263180002', 'CIB', 'Ahmed Ali', 1500); // min 112 EGP
DisbursementRequest::post('29001010000000', 'Ahmed Ali', 300);          // cash at Egypt Post

// Options
$request = DisbursementRequest::vodafone('01001234567', 100)
    ->withClientReferenceId('my-own-unique-id')   // default: a random UUID
    ->withCustomerBearsFees();                     // receiver pays the fees (default: you do)
```

| Issuer | Code | Needs |
|---|---|---|
| Vodafone Cash | `vodafone` | `msisdn` |
| Etisalat Cash | `etisalat` | `msisdn` |
| Orange Cash | `orange` | `msisdn`, `full_name` |
| Bank wallet | `bank_wallet` | `msisdn`, `full_name` |
| Bank card | `bank_card` | `bank_card_number`, `bank_code`, `full_name`, `bank_transaction_type` (`salary`, `credit_card`, `prepaid_card`, `cash_transfer`) |
| Instant bank transfer | `instant_bank` | `bank_card_number` (account / IBAN), `bank_code`, `full_name` — min 112 EGP |
| Egypt Post | `post` | `national_id`, `full_name` |

Phone numbers are normalised for you: `+20 100 123 4567`, `00201001234567`, `1001234567` and Arabic digits all become `01001234567`. Requests are validated before anything is sent (`ValidationException` with per-field errors). Bank codes come from Paymob (the `bank_card` and `instant_bank` lists differ).

### Results

| Method | Meaning |
|---|---|
| `isSuccessful()` | Money sent. |
| `isFailed()` | Refused by Paymob; nothing sent. |
| `isPending()` | Paymob is processing it. |
| `isUnknown()` | No clear answer (timeout / server error). **Check before resending.** |
| `transactionId()`, `clientReferenceId()`, `statusCode()`, `statusDescription()`, `raw()` | Details. |

### Other calls

```php
$paymob->transactionStatus($transactionId);            // by Paymob id
$paymob->transactionStatus(null, $clientReferenceId);  // by your reference
$paymob->inquire(['tx-1', 'tx-2']);                    // raw inquiry
$paymob->balance();                                    // e.g. 15250.5
```

### Errors

All exceptions extend `OmarRaafat\PaymobPayouts\Exceptions\PaymobPayoutsException`:

- `ValidationException` — bad input, nothing sent (`->errors()` per field)
- `AuthenticationException` — Paymob refused the credentials, nothing sent
- `ApiException` — an inquiry / balance call failed

`disburse()` itself never throws for a payout outcome — it returns a result.

---

## Laravel

Auto-discovered. Publish the config and migration, then migrate:

```bash
php artisan vendor:publish --tag=paymob-payouts-config
php artisan vendor:publish --tag=paymob-payouts-migrations
php artisan migrate
```

`.env`:

```dotenv
PAYMOB_PAYOUTS_CLIENT_ID=
PAYMOB_PAYOUTS_CLIENT_SECRET=
PAYMOB_PAYOUTS_USERNAME=
PAYMOB_PAYOUTS_PASSWORD=
PAYMOB_PAYOUTS_ENV=staging
```

Check the credentials work:

```bash
php artisan paymob-payouts:balance
```

### Sending with `PayoutManager` (recommended)

```php
use OmarRaafat\PaymobPayouts\DisbursementRequest;
use OmarRaafat\PaymobPayouts\Laravel\Exceptions\DuplicatePayoutException;
use OmarRaafat\PaymobPayouts\Laravel\PayoutManager;

try {
    $payout = app(PayoutManager::class)->send(
        "invoice-{$invoice->id}",                         // your reference: paid at most once
        DisbursementRequest::vodafone($wallet->msisdn, $invoice->total),
        [
            'payable'    => $merchant,                    // any Eloquent model
            'created_by' => auth()->id(),
            'meta'       => ['invoice_id' => $invoice->id],
        ]
    );
} catch (DuplicatePayoutException $e) {
    // Already paid / in progress: $e->existing()
}

$payout->status;          // successful | failed | pending | unknown
$payout->transaction_id;
```

- One **reference** = at most one successful / in-flight payout. After a **failed** one you can send it again.
- Every attempt is a row in `paymob_payouts` (amount, destination, status, Paymob's answer, who sent it, what it was for).

### Events

| Event | When |
|---|---|
| `PayoutSucceeded` | Money sent (on send or when reconciled). |
| `PayoutFailed` | Refused; nothing sent. |
| `PayoutPending` | Pending or unknown; will be reconciled. |

```php
Event::listen(PayoutSucceeded::class, function ($event) {
    $event->payout->payable->notify(new PayoutSentNotification($event->payout));
});
```

### Settling timed-out payouts

```php
// app/Console/Kernel.php
$schedule->command('paymob-payouts:reconcile')->everyFiveMinutes()->withoutOverlapping();
```

It asks Paymob about payouts that are pending, unknown or were interrupted, and records the final result (firing the events). A payout Paymob cannot find is **never** marked failed automatically — check it in the Paymob dashboard, then:

```php
app(PayoutManager::class)->markFailed($payout, 'Checked in Paymob dashboard: not sent.');
app(PayoutManager::class)->markSuccessful($payout, 'Found in Paymob dashboard.', 'tx-123');
```

### Facade

```php
use OmarRaafat\PaymobPayouts\Laravel\Facades\PaymobPayouts;

PaymobPayouts::balance();
PaymobPayouts::disburse(DisbursementRequest::etisalat('01101234567', 50)); // no record / duplicate check
```

---

## Security checklist

- Keep the four credentials in environment variables or a secrets manager; never commit them.
- Only **trusted, authenticated** code paths should be able to trigger a payout. Never expose a public "send payout" endpoint without authentication, authorisation and an audit log.
- Restrict who can change payout destinations (wallet number, card, IBAN), and notify the account owner when they change.
- Use a **shared** cache / token store in production so tokens are reused across servers.
- Schedule `paymob-payouts:reconcile`, and alert on payouts stuck in `unknown`.
- Test on **staging** first; switch `PAYMOB_PAYOUTS_ENV=production` only when ready.

## Not yet confirmed by Paymob's public docs

These follow Paymob's Postman collection but are not fully documented publicly; confirm them with Paymob for your account:

- Inquiry **by reference** path (`/api/secure/transaction/inquire/by-reference/`).
- Exact shape of inquiry and budget answers (the client accepts the common shapes).
- The full list of status codes and `disbursement_status` values (`successful`, `pending`, `failed` are handled; anything unrecognised is treated as **unknown**, never as success).

## Testing

```bash
composer install
composer test
```

The tests use recorded fake Paymob answers — they never send real money.

## License

MIT — see [LICENSE](LICENSE).
