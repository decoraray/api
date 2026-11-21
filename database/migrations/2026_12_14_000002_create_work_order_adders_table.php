<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Service adders on a job, with the unit cost they had when added so later price changes leave them alone.
        Schema::create('work_order_adders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cost_adder_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->unsignedBigInteger('unit_cost');
            // Copied from the design (replaced when the work order switches design) or added by hand.
            $table->boolean('from_design')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('work_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_adders');
    }
};
