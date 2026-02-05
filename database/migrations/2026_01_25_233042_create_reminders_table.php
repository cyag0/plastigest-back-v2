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
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('type', ['payment', 'renewal', 'expiration', 'other'])->default('other');
            $table->date('reminder_date');
            $table->time('reminder_time')->nullable();
            
            $table->enum('status', ['pending', 'completed', 'overdue'])->default('pending');
            $table->timestamp('completed_at')->nullable();
            
            // Recurrencia
            $table->boolean('is_recurring')->default(false);
            $table->enum('recurrence_type', ['daily', 'weekly', 'monthly', 'yearly'])->nullable();
            $table->integer('recurrence_interval')->default(1)->comment('Cada cuántos días/semanas/meses');
            $table->date('recurrence_end_date')->nullable();
            
            // Notificaciones
            $table->boolean('notify_enabled')->default(true);
            $table->integer('notify_days_before')->default(1)->comment('Días antes para notificar');
            $table->timestamp('last_notified_at')->nullable();
            
            // Referencias opcionales
            $table->foreignId('supplier_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('set null');
            
            $table->decimal('amount', 10, 2)->nullable()->comment('Monto asociado (para pagos)');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Índices
            $table->index(['company_id', 'reminder_date']);
            $table->index(['status', 'reminder_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
