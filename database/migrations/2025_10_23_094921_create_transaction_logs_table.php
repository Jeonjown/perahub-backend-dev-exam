<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transaction_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('partner_id')->nullable()->after('id');
            $table->enum('type', ['RAW', 'ACTUAL']);
            $table->string('endpoint');
            $table->json('request_body')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_logs');
    }
};
