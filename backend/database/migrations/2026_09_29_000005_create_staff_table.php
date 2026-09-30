<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            // Logins for staff are linked later, so this stays nullable for now.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_id')->nullable()->unique();
            // At least one of name_en/name_bn is required; enforced in the FormRequest,
            // not the database, because either one alone is a valid Bangla-friendly name.
            $table->string('name_en')->nullable();
            $table->string('name_bn')->nullable();
            $table->enum('category', ['teacher', 'staff']);
            $table->enum('position', ['head', 'assistant_head', 'teacher', 'staff']);
            $table->string('designation')->nullable();
            $table->string('subject')->nullable();
            $table->string('mpo_index')->nullable();
            $table->date('joining_date')->nullable();
            $table->date('leaving_date')->nullable();
            $table->enum('status', ['active', 'retired', 'transferred', 'resigned', 'deceased'])->default('active');
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('religion')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('blood_group', ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'])->nullable();
            $table->string('nationality')->default('Bangladeshi');
            $table->string('nid')->nullable();
            $table->string('mobile')->nullable();
            $table->string('email')->nullable();
            $table->boolean('show_contact')->default(false);
            $table->text('present_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->string('district')->nullable();
            $table->string('photo')->nullable();
            $table->text('bio')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'position', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
