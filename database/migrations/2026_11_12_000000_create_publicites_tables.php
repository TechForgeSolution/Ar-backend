<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publicites', function (Blueprint $table) {
            $table->id();
            $table->string('image_path');
            $table->text('redirect_url');
            $table->boolean('active')->default(true);
            $table->timestamp('published_at');
            $table->timestamps();

            $table->index(['active', 'published_at']);
        });

        Schema::create('publicite_vues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publicite_id')->constrained('publicites')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('auto_ecole_users')->cascadeOnDelete();
            $table->timestamp('vu_at');
            $table->timestamp('pris_note_at')->nullable();
            $table->timestamps();

            $table->unique(['publicite_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publicite_vues');
        Schema::dropIfExists('publicites');
    }
};
