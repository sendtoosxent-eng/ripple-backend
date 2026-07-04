<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['text', 'image', 'voice'])->default('text');
            $table->text('text')->nullable();          // for type=text, or image caption
            $table->string('media_path')->nullable();  // for type=image / type=voice (storage path)
            $table->string('voice_duration')->nullable(); // e.g. "0:14"
            $table->json('waveform')->nullable();       // array of floats for the voice waveform UI
            $table->unsignedInteger('width')->nullable();  // image width
            $table->unsignedInteger('height')->nullable(); // image height
            $table->enum('status', ['sent', 'delivered', 'read'])->default('sent');
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
