<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bea_messages', function (Blueprint $table) {
            $table->id();
            $table->string('bea_id', 64)->nullable()->unique();
            $table->string('osci_id', 191)->nullable()->index();
            $table->string('subject', 1024)->nullable();

            $table->string('sender_name', 512)->nullable();
            $table->string('sender_safe_id', 191)->nullable()->index();
            $table->string('sender_role', 64)->nullable();
            $table->string('recipient_name', 512)->nullable();
            $table->string('recipient_safe_id', 191)->nullable();

            $table->string('aktenzeichen', 191)->nullable()->index();
            $table->string('aktenzeichen_absender', 191)->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable()->index();
            $table->timestamp('intermediary_at')->nullable();

            $table->boolean('secure_channel')->nullable();
            $table->boolean('eeb_requested')->default(false);
            $table->string('integrity', 32)->nullable();      // complete | incomplete | missing
            $table->string('vhn_signature', 32)->nullable();  // valid | signature_ok | invalid | missing | notool | error

            $table->foreignId('zip_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->nullableMorphs('assignable');              // Zuordnung durch den Listener (z. B. Akte)
            $table->foreignId('imported_by')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::create('bea_message_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bea_message_id')->constrained('bea_messages')->cascadeOnDelete();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('role', 32);                         // attachment | signature | report | export | zip
            $table->string('name', 512);
            $table->string('display_name', 512)->nullable();
            $table->string('document_class', 16)->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('vhn_covered')->nullable();
            $table->string('signature_of', 512)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bea_message_files');
        Schema::dropIfExists('bea_messages');
    }
};
