<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('income_id')->index();
            $table->string('number')->nullable();
            $table->date('date')->nullable();
            $table->date('last_change_date')->nullable();
            $table->string('supplier_article')->nullable();
            $table->string('tech_size')->nullable();
            $table->string('barcode')->nullable();
            $table->integer('quantity')->nullable();
            $table->decimal('total_price', 15, 2)->nullable();
            $table->date('date_close')->nullable();
            $table->string('warehouse_name')->nullable();
            $table->unsignedBigInteger('nm_id')->nullable()->index();
            // Хэш натурального ключа строки — для идемпотентного upsert
            $table->char('uniq_hash', 32)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incomes');
    }
};
