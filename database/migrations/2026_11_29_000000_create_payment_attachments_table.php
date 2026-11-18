<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Images/PDFs of payment documents (card-to-card slips, cheques). Uploaded first with
        // payment_id null, then attached when the payment is recorded; orphans are pruned daily.
        Schema::create('payment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('disk')->default('receipts');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime');
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['uploaded_by', 'payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attachments');
    }
};
