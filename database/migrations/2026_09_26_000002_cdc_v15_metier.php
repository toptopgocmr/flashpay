<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cahier des charges v1.5 — fonctionnalités métier manquantes :
 *  §3.1.2  demandes d'approvisionnement (float) + super-agents
 *  §3.1.7  barème de commissions agents
 *  §3.2.2  QR dynamique / liens de paiement / NFC (payment_requests)
 *  §3.2.3  caissiers (sous-comptes marchands)
 *  §3.2.5 / §4.7 API e-commerce : clés, payment intents, remboursements, webhooks
 *  §3.3.5  comptes liés clients
 *  §3.5    enveloppes rouges, partage de note, mini-programmes
 *  §13.2   litiges ; §16 tickets support
 *  §13.1   rapports de réconciliation
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------- Agents
        Schema::table('agents', function (Blueprint $table) {
            $table->string('agent_code', 20)->nullable()->unique()->after('user_id');
            $table->string('pos_code', 20)->nullable()->after('agent_code');
            $table->boolean('is_super_agent')->default(false)->after('pos_code');
            $table->foreignId('parent_agent_id')->nullable()->after('is_super_agent')->constrained('agents')->nullOnDelete();
            $table->unsignedBigInteger('low_float_threshold')->default(50000)->after('float_balance');
            $table->timestamp('low_float_alerted_at')->nullable()->after('low_float_threshold');
        });

        Schema::create('float_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('super_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('XAF');
            $table->string('method', 20); // cash_deposit | bank_transfer | super_agent
            $table->string('proof_reference', 120)->nullable();
            $table->string('note', 200)->nullable();
            $table->string('status', 15)->default('pending'); // pending | approved | rejected | cancelled
            $table->string('rejection_reason', 200)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('operation', 30); // cash_in | cash_out | float_request | external_transfer | p2p
            $table->unsignedBigInteger('min_amount')->default(0);
            $table->unsignedBigInteger('max_amount')->nullable();
            $table->string('type', 10); // fixed | percent | fee_share (% des frais client)
            $table->decimal('value', 10, 3);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['operation', 'active']);
        });

        // ------------------------------------------------------------- Marchands
        Schema::create('merchant_cashiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('merchant_outlets')->nullOnDelete();
            $table->string('status', 10)->default('active'); // active | revoked
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 150)->nullable();
            $table->timestamps();
        });

        Schema::table('merchants', function (Blueprint $table) {
            $table->boolean('online_payments')->default(false)->after('validation_status');
        });

        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('token', 40)->unique();
            $table->string('kind', 20); // dynamic_qr | payment_link | nfc | split_share
            $table->foreignId('merchant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->nullable()->constrained('merchant_outlets')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('XAF');
            $table->string('reference', 80)->nullable();
            $table->string('description', 190)->nullable();
            $table->string('status', 15)->default('pending'); // pending | paid | expired | cancelled
            $table->timestamp('expires_at');
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['merchant_id', 'status']);
        });

        Schema::create('merchant_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 10); // sandbox | live
            $table->string('public_key', 60)->unique();
            $table->string('secret_hash', 64)->unique();
            $table->string('secret_last4', 4);
            $table->string('webhook_url')->nullable();
            $table->text('webhook_secret');
            $table->json('allowed_ips')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['merchant_id', 'environment']);
        });

        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique(); // pi_...
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('merchant_api_keys')->nullOnDelete();
            $table->string('environment', 10);
            $table->string('channel', 20)->default('ecommerce'); // ecommerce | mini_program
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('XAF');
            $table->string('order_reference', 120)->nullable();
            $table->string('description', 190)->nullable();
            $table->string('customer_phone', 25)->nullable();
            $table->string('return_url')->nullable();
            $table->string('cancel_url')->nullable();
            $table->string('status', 25)->default('requires_confirmation');
            // requires_confirmation | processing | succeeded | failed | expired | canceled | refunded | partially_refunded
            $table->unsignedBigInteger('amount_refunded')->default(0);
            $table->foreignId('payer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('failure_reason', 190)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamps();
            $table->index(['merchant_id', 'status']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique(); // re_...
            $table->foreignId('transaction_id')->constrained(); // paiement d'origine
            $table->foreignId('payment_intent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('reason', 190)->nullable();
            $table->string('initiated_by_role', 15); // merchant | admin | api
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 15)->default('completed'); // completed | failed
            $table->foreignId('refund_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('merchant_api_keys')->nullOnDelete();
            $table->string('event', 50);
            $table->string('url');
            $table->json('payload');
            $table->string('status', 15)->default('pending'); // pending | delivered | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_response_code')->nullable();
            $table->string('last_error', 190)->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });

        // --------------------------------------------------------------- Clients
        Schema::create('linked_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 15); // mobile_money | bank
            $table->string('label', 80)->nullable();
            $table->string('country', 2);
            $table->string('phone', 25)->nullable();
            $table->string('operator', 60)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('account_holder', 120)->nullable();
            $table->string('account_number', 60)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('gift_envelopes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 10); // fixed | random
            $table->unsignedBigInteger('total_amount');
            $table->unsignedBigInteger('remaining_amount');
            $table->unsignedSmallInteger('shares');
            $table->unsignedSmallInteger('claimed_shares')->default(0);
            $table->string('currency', 3);
            $table->string('message', 190)->nullable();
            $table->string('occasion', 30)->nullable(); // anniversaire | fete | felicitations | autre
            $table->string('status', 15)->default('active'); // active | completed | expired | refunded
            $table->timestamp('expires_at');
            $table->boolean('reminder_sent')->default(false);
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('gift_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gift_envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_phone', 25)->nullable();
            $table->unsignedBigInteger('amount')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bill_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 120);
            $table->unsignedBigInteger('total_amount');
            $table->string('currency', 3);
            $table->string('mode', 10); // equal | custom
            $table->string('status', 15)->default('open'); // open | settled | cancelled
            $table->timestamps();
        });

        Schema::create('bill_split_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_split_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 25);
            $table->unsignedBigInteger('amount');
            $table->string('status', 15)->default('pending'); // pending | paid | declined | self
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mini_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('category', 30); // recharge | billetterie | services_publics | ecommerce | autre
            $table->string('description', 255)->nullable();
            $table->string('icon_url')->nullable();
            $table->string('entry_url');
            $table->string('status', 15)->default('pending'); // pending | approved | suspended
            $table->unsignedSmallInteger('sort')->default(100);
            $table->timestamps();
        });

        // --------------------------------------------------- Litiges et support
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 30); // unrecognized | wrong_amount | cash_out_not_received | cash_in_not_credited | merchant_not_delivered | other
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open'); // open | investigating | resolved_refunded | resolved_rejected
            $table->timestamp('sla_due_at');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('refund_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'sla_due_at']);
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30); // blocking_incident | account | information | security_report | kyc
            $table->string('subject', 150);
            $table->string('priority', 10)->default('normal'); // normal | high | critical
            $table->string('status', 15)->default('open'); // open | pending_user | resolved | closed
            $table->timestamp('sla_due_at');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'sla_due_at']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users');
            $table->boolean('from_staff')->default(false);
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('reconciliation_reports', function (Blueprint $table) {
            $table->id();
            $table->date('report_date');
            $table->unsignedInteger('anomalies')->default(0);
            $table->json('results');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'reconciliation_reports', 'support_messages', 'support_tickets', 'disputes', 'mini_programs',
            'bill_split_shares', 'bill_splits', 'gift_claims', 'gift_envelopes', 'linked_accounts',
            'webhook_deliveries', 'refunds', 'payment_intents', 'merchant_api_keys', 'payment_requests',
            'merchant_cashiers', 'commission_rules', 'float_requests',
        ] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('merchants', fn (Blueprint $t) => $t->dropColumn('online_payments'));
        Schema::table('agents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_agent_id');
            $table->dropColumn(['agent_code', 'pos_code', 'is_super_agent', 'low_float_threshold', 'low_float_alerted_at']);
        });
    }
};
