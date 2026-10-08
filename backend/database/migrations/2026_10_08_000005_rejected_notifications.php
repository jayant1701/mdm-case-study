<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rejected_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('topic');
            $table->integer('partition');
            $table->bigInteger('offset');
            $table->longText('payload');
            $table->text('error');
            $table->timestamp('created_at');
            $table->unique(['topic', 'partition', 'offset']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rejected_notifications');
    }
};
