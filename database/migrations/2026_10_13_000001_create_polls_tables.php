<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            // Unguessable id used in public links instead of the auto-increment id.
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            // Creator's timezone when the poll was made; slots themselves are stored in UTC.
            $table->string('timezone');
            $table->string('status')->default('open'); // open, finalized, closed
            $table->foreignId('final_slot_id')->nullable();
            $table->string('google_event_id')->nullable();
            $table->timestamps();
        });

        Schema::create('poll_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at'); // UTC
            $table->dateTime('ends_at');   // UTC
            $table->timestamps();

            $table->unique(['poll_id', 'starts_at']);
        });

        // Everyone attached to a poll: people invited by email and people who
        // answered through the shared link. The token lets them edit their answers.
        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('token', 64)->unique();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['poll_id', 'email']);
        });

        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('poll_slot_id')->constrained()->cascadeOnDelete();
            $table->string('choice'); // yes, maybe, no
            $table->timestamps();

            $table->unique(['participant_id', 'poll_slot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votes');
        Schema::dropIfExists('participants');
        Schema::dropIfExists('poll_slots');
        Schema::dropIfExists('polls');
    }
};
