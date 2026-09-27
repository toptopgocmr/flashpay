<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->unique();
            $table->string('password');
            $table->enum('kyc_status', ['pending', 'submitted', 'verified', 'rejected'])->default('pending');
            $table->enum('status', ['active', 'suspended', 'blocked'])->default('active');
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('phone_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
