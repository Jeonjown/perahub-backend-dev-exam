<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
        {
        Schema::create('pending_transactions', function (Blueprint $table) {
        $table->id();
        $table->string('transaction_id')->nullable();
        $table->json('request_body');
        $table->text('error_message')->nullable();
        $table->string('status')->default('pending');
        $table->timestamps();
    });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_transactions');
    }
};
