<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cahier des charges v1.5 — socle sécurité & conformité :
 *  §3.3.1 / §4.5  OTP, PIN applicatif, paliers KYC
 *  §12            plafonds par palier (kyc_tier)
 *  §13.1          clés d'idempotence
 *  §15            appareils / sessions, réinitialisation du PIN
 *  §11            notifications par profil
 *  §3.4.3 / §11.4 journal d'audit immuable des interventions
 *  §14            paramètres de plateforme (mode dégradé)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('kyc_tier')->default(0)->after('kyc_status');
            $table->string('pin_hash')->nullable()->after('password');
            $table->unsignedTinyInteger('pin_attempts')->default(0)->after('pin_hash');
            $table->timestamp('pin_locked_until')->nullable()->after('pin_attempts');
            $table->timestamp('pin_changed_at')->nullable()->after('pin_locked_until');
            $table->string('language', 5)->default('fr')->after('email');
            $table->unsignedSmallInteger('risk_score')->default(0)->after('status');
            $table->timestamp('blocked_until')->nullable()->after('risk_score');
            $table->string('id_number', 60)->nullable()->after('kyc_tier');
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 25);
            $table->string('purpose', 30); // register | login_device | sensitive | pin_reset | checkout | cash_in
            $table->string('code_hash', 64);
            $table->json('context')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['phone', 'purpose']);
        });

        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_id', 120);
            $table->string('name', 120)->nullable();
            $table->string('platform', 20)->nullable(); // android | ios | web
            $table->string('app_version', 20)->nullable();
            $table->string('push_token')->nullable();
            $table->boolean('nfc_hce')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_id']);
        });

        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // id_card | passport | selfie | trade_register | pos_proof | proof_of_address
            $table->string('path');
            $table->string('status', 15)->default('pending'); // pending | approved | rejected
            $table->string('rejection_reason', 200)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100);
            $table->string('scope', 120); // user:12 | merchant_key:4
            $table->string('route', 190);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'key']);
        });

        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // null = centre admin
            $table->string('audience', 10)->default('user'); // user | admin
            $table->string('type', 50);
            $table->string('severity', 10)->default('info'); // info | success | warning | critical
            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->boolean('sound')->default(false);
            $table->timestamp('sms_sent_at')->nullable();
            $table->timestamp('push_sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
            $table->index(['audience', 'read_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('data')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('hash', 64)->nullable(); // chaînage : sha256(hash précédent + contenu)
            $table->timestamp('created_at')->nullable();
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('fraud_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule', 50);
            $table->unsignedSmallInteger('score')->default(0);
            $table->string('status', 15)->default('open'); // open | cleared | confirmed
            $table->json('details')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['fraud_alerts', 'platform_settings', 'audit_logs', 'app_notifications', 'idempotency_keys', 'kyc_documents', 'user_devices', 'otp_codes'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['kyc_tier', 'pin_hash', 'pin_attempts', 'pin_locked_until', 'pin_changed_at', 'language', 'risk_score', 'blocked_until', 'id_number']);
        });
    }
};
