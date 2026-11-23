<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per payout attempt, written BEFORE Paymob is called, so a crash or timeout
 * never leaves money sent without a record.
 */
class CreatePaymobPayoutsTable extends Migration
{
    public function up()
    {
        $table = config('paymob-payouts.table', 'paymob_payouts');

        Schema::create($table, function (Blueprint $table) {
            $table->bigIncrements('id');

            // Your business key (e.g. "invoice-1042"). Only one successful /
            // in-flight payout per reference: PayoutManager refuses to send it twice.
            $table->string('reference', 191)->index();
            // Sent to Paymob; unique per attempt; used to find the payout if the call timed out.
            $table->string('client_reference_id', 140)->unique();

            // What the payout is for (merchant, order, invoice...) and who sent it.
            $table->nullableMorphs('payable');
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->string('issuer', 30);
            $table->string('destination', 64);          // msisdn / card or account number / national id
            $table->string('full_name')->nullable();
            $table->decimal('amount', 12, 2);
            $table->boolean('customer_bears_fees')->default(false);

            // sending → successful | failed | pending | unknown (pending/unknown are re-checked)
            $table->string('status', 20)->default('sending')->index();
            $table->string('transaction_id', 64)->nullable()->index();
            $table->string('paymob_status', 50)->nullable();
            $table->string('status_code', 20)->nullable();
            $table->text('status_description')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('response')->nullable();
            $table->json('meta')->nullable();

            $table->unsignedInteger('checks')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists(config('paymob-payouts.table', 'paymob_payouts'));
    }
}
