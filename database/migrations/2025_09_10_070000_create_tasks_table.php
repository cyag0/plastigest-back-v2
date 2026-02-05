<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('location_id')->nullable()->constrained('locations')->onDelete('cascade');
            
            $table->string('title');
            $table->text('description')->nullable();
            
            $table->enum('type', [
                'inventory_count',
                'receive_purchase',
                'approve_transfer',
                'send_transfer',
                'receive_transfer',
                'sales_report',
                'stock_check',
                'adjustment_review',
                'custom'
            ]);
            $table->enum('status', [
                'pending',
                'in_progress',
                'completed',
                'cancelled',
                'overdue'
            ])->default('pending');
            $table->enum('priority', [
                'low',
                'medium',
                'high',
                'urgent'
            ])->default('medium');
            
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('completed_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->timestamp('due_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            $table->nullableMorphs('related');
            
            $table->boolean('is_recurring')->default(false);
            $table->enum('recurrence_frequency', [
                'daily',
                'weekly',
                'biweekly',
                'monthly'
            ])->nullable();
            $table->integer('recurrence_day')->nullable();
            $table->time('recurrence_time')->nullable();
            $table->timestamp('last_generated_at')->nullable();
            $table->timestamp('next_occurrence')->nullable();
            
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['company_id', 'status', 'due_date']);
            $table->index(['assigned_to', 'status']);
            $table->index(['location_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
