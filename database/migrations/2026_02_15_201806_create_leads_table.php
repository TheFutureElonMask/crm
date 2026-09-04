<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            
            // Ответственный менеджер
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            
            // Данные клиента
            $table->string('client_name')->nullable();
            $table->string('phone')->index();
            $table->string('email')->nullable();
            
            // Детали сделки
            $table->string('title'); 
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2)->default(0);
            
            // Статус воронки
            $table->string('status')->default('new'); // new, in_progress, pending, won, lost
            $table->string('source')->nullable();     // whatsapp, site, manual
            
            // Планирование
            $table->timestamp('next_action_at')->nullable(); 

            $table->string('chat_step')->default('new'); // Этапы: new, wait_name, wait_question, completed
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
