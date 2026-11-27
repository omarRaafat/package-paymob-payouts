# Paymob Payouts for PHP & Laravel

Send money from your **Paymob Payouts** balance to Egyptian **mobile wallets** (Vodafone Cash, Etisalat Cash, Orange Cash, bank wallets), **bank cards**, **bank accounts** (instant transfer) and **Egypt Post** — with the safety checks a real payout system needs.

- **Plain PHP client** (PHP 7.4 – 8.3, any framework): log in, send a payout, check its status, read your balance.
- **Laravel add-on** (optional): a `paymob_payouts` table, **duplicate-payout protection**, events, and a command that settles timed-out payouts.

> Built on Paymob's **official Payouts API**, following Paymob's own documentation and request formats, with duplicate-payout protection built in. Test on Paymob **staging** before going live.

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

| Requirement | Supported |
|---|---|
| PHP | 7.4 – 8.3 |
| Laravel (optional) | 8 – 12 (auto-discovered) |
| Paymob | A **Payouts** account (staging and / or production) |

You need **Paymob Payouts** credentials (client id, client secret, username, password). These are different from Paymob Accept (online payments) keys — ask your Paymob account manager. Using Laravel? Skip to [Laravel in 5 steps](#laravel).

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

**Minimum amounts:** Paymob refuses payouts under **5 EGP** (answer: `amount: Ensure this value is greater than or equal to 5.`), and instant bank transfers under **112 EGP**. Nothing is sent in either case.

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

The service provider and facade are auto-discovered. Five steps:

**1. Install, publish the config and the migration, migrate**

```bash
composer require omarraafat/paymob-payouts
php artisan vendor:publish --tag=paymob-payouts-config
php artisan vendor:publish --tag=paymob-payouts-migrations
php artisan migrate        # creates the paymob_payouts table
```

**2. Add your credentials to `.env`**

```dotenv
PAYMOB_PAYOUTS_CLIENT_ID=
PAYMOB_PAYOUTS_CLIENT_SECRET=
PAYMOB_PAYOUTS_USERNAME=
PAYMOB_PAYOUTS_PASSWORD=
PAYMOB_PAYOUTS_ENV=staging
```

**3. Check the credentials work**

```bash
php artisan paymob-payouts:balance
# Paymob Payouts balance: 1,734.56 EGP
```

**4. Send a payout with `PayoutManager`**

```php
use OmarRaafat\PaymobPayouts\DisbursementRequest;
use OmarRaafat\PaymobPayouts\Exceptions\PaymobPayoutsException;
use OmarRaafat\PaymobPayouts\Exceptions\ValidationException;
use OmarRaafat\PaymobPayouts\Laravel\Exceptions\DuplicatePayoutException;
use OmarRaafat\PaymobPayouts\Laravel\PayoutManager;

try {
    $payout = app(PayoutManager::class)->send(
        "invoice-{$invoice->id}",                         // your reference: paid at most once
        DisbursementRequest::vodafone($wallet->msisdn, $invoice->total),
        [
            'payable'    => $merchant,                    // any Eloquent model (optional)
            'created_by' => auth()->id(),                 // optional
            'meta'       => ['invoice_id' => $invoice->id], // optional
        ]
    );
} catch (ValidationException $e) {
    // Bad input (e.g. wrong number): nothing sent. $e->errors() per field.
} catch (DuplicatePayoutException $e) {
    // Already paid or in progress: nothing sent again. $e->existing() is that payout.
} catch (PaymobPayoutsException $e) {
    // Paymob refused the credentials, or similar: nothing sent.
}

if ($payout->isSuccessful()) {
    // Money sent: $payout->transaction_id
} elseif ($payout->isFailed()) {
    // Refused, nothing sent: $payout->status_description says why. Fix it and send the same reference again.
} else {
    // pending / unknown: do NOT send again. Step 5 settles it; you get an event.
}
```

- One **reference** = at most one successful / in-flight payout. After a **failed** one you can send it again.
- Every attempt is a row in `paymob_payouts`. Query it with the `OmarRaafat\PaymobPayouts\Laravel\Models\Payout` model:

| On `Payout` | |
|---|---|
| `status` | `successful`, `failed`, `pending`, `unknown` (or `sending` while the call is running) |
| `isSuccessful()`, `isFailed()`, `isInFlight()` | `isInFlight()` = pending, unknown or sending: wait, don't resend |
| `reference`, `client_reference_id`, `transaction_id` | Your reference, the id sent to Paymob, Paymob's id |
| `amount`, `issuer`, `destination`, `maskedDestination()` | What was sent where |
| `status_code`, `status_description`, `response` | Paymob's answer |
| `payable`, `created_by`, `meta` | What it was for and who sent it |

**5. Schedule the reconcile command** (settles pending and timed-out payouts)

```php
// routes/console.php (Laravel 11 and 12)
use Illuminate\Support\Facades\Schedule;

Schedule::command('paymob-payouts:reconcile')->everyFiveMinutes()->withoutOverlapping();
```

```php
// app/Console/Kernel.php, schedule() method (Laravel 8 – 10)
$schedule->command('paymob-payouts:reconcile')->everyFiveMinutes()->withoutOverlapping();
```

Make sure the scheduler runs (`php artisan schedule:work`, or the usual `schedule:run` cron entry).

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

The `paymob-payouts:reconcile` command (step 5) asks Paymob about payouts that are pending, unknown or were interrupted, and records the final result (firing the events). A payout Paymob cannot find is **never** marked failed automatically — check it in the Paymob dashboard, then:

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

## Testing on Paymob staging

Staging moves test money only, but it behaves like production. Before your first test payout:

1. **Use Paymob's staging test numbers.** Only these get a successful answer on staging (and they work on staging only). Other numbers are refused with `Request type is not supported`: the payout gets a `transaction_id` but fails, and nothing is sent.

   | Issuer | Test destination | Example |
   |---|---|---|
   | `vodafone` | `01023456789` | `DisbursementRequest::vodafone('01023456789', 10)` |
   | `etisalat` | `01123456789` | `DisbursementRequest::etisalat('01123456789', 10)` |
   | `orange` | `01223456789` | `DisbursementRequest::orange('01223456789', 10, 'Test User')` |
   | `bank_wallet` | `01123416789` | `DisbursementRequest::bankWallet('01123416789', 10, 'Test User')` |
   | `bank_card` | card `1111222233334444` or IBAN `EG829299835722904511873050307` | `DisbursementRequest::bankCard('1111222233334444', 'CIB', 'Test User', 10)` |
   | `instant_bank` | account `1234567890123456` or IBAN `EG187277769381221446527989011` | `DisbursementRequest::instantBank('1234567890123456', 'CIB', 'Test User', 112)` |

   For `bank_card` and `instant_bank`, use a bank code from Paymob's bank list (`CIB` above is an example).

   ```bash
   # In a Laravel app, quick check from the terminal:
   php artisan tinker --execute="dump(app(\OmarRaafat\PaymobPayouts\Laravel\PayoutManager::class)->send('staging-test-1', \OmarRaafat\PaymobPayouts\DisbursementRequest::vodafone('01023456789', 10))->status);"
   ```

2. **Send at least 5 EGP** (112 EGP for instant bank transfers).
3. Check the balance first (`php artisan paymob-payouts:balance`): if it works, your credentials and `PAYMOB_PAYOUTS_ENV` are right.
4. When a payout fails, read `status_description` (or `$payout->response` for Paymob's full answer). It says why.

| You see | Meaning | What to do |
|---|---|---|
| `AuthenticationException` / balance command fails | Wrong credentials, or staging credentials with `PAYMOB_PAYOUTS_ENV=production` (or the reverse) | Check the four values and the environment |
| `failed`: `amount: Ensure this value is greater than or equal to 5.` | Below Paymob's minimum | Send 5 EGP or more |
| `failed`: `Request type is not supported` | The number isn't a wallet Paymob can pay (on staging: not one of the test numbers above) | On staging, use the test number for that issuer; in production, check the number and issuer |
| `unknown` | Timeout or server error: the money may have left | Don't resend; wait for `paymob-payouts:reconcile` |

Going live: switch to your production credentials and `PAYMOB_PAYOUTS_ENV=production`, then send one small real payout first.

---

## Security checklist

- Keep the four credentials in environment variables or a secrets manager; never commit them.
- Only **trusted, authenticated** code paths should be able to trigger a payout. Never expose a public "send payout" endpoint without authentication, authorisation and an audit log.
- Restrict who can change payout destinations (wallet number, card, IBAN), and notify the account owner when they change.
- Use a **shared** cache / token store in production so tokens are reused across servers.
- Schedule `paymob-payouts:reconcile`, and alert on payouts stuck in `unknown`.
- Test on **staging** first; switch `PAYMOB_PAYOUTS_ENV=production` only when ready.

## Verified against Paymob staging

Tested end to end on `stagingpayouts.paymobsolutions.com` (Vodafone wallet payouts):

- Token: `expires_in` 3600, `token_type` Bearer, with a `refresh_token`.
- Disburse answer: `transaction_id`, `disbursement_status` (`successful`), `status_code` (`"200"`), `status_description`, `fees`, `vat`, and our id echoed as `client_transaction_reference` (available as `$result->fees()` / `$result->vat()`).
- Inquiry by id and **by reference** both answer `{"count", "next", "previous", "results": [...]}`; an unknown id gives an empty `results` list. The by-reference inquiry searches the `reference` field, which this package fills with the client reference id — so a payout that timed out is found and settled.
- Budget: `{"current_budget": "Your current budget is 1811.49 LE", "current_balance": 1811.49, "currency": "EGP"}`.

Still to confirm with Paymob for your account: the full list of failure `status_code` values, and other issuers (bank card, instant bank, Egypt Post) on staging. Unrecognised statuses are treated as **unknown**, never as success.

## Testing

```bash
composer install
composer test
```

The tests use recorded fake Paymob answers — they never send real money.

## License

MIT — see [LICENSE](LICENSE).
