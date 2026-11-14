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
            // Original-ZIP auf der Disk „beas“ (mehrere Nachrichten einer ZIP teilen sich die Datei)
            $table->string('archive_disk', 64)->nullable();
            $table->string('archive_path', 1024)->nullable();
            $table->string('archive_name', 512)->nullable();     // Dateiname beim Einlesen
            $table->string('archive_sha256', 64)->nullable()->index(); // Prüfsumme beim Einlesen
            $table->unsignedBigInteger('archive_size')->nullable();
            $table->string('archive_source', 32)->nullable();   // upload | inbox | console
            $table->string('directory', 1024)->nullable();      // Ordner der Nachricht in der ZIP
            $table->string('status', 32)->default('ingested')->index(); // ingested | accepted
            // in = empfangen, out = gesendet (Journal der Exportansicht bzw. eigene SAFE-ID)
            $table->string('direction', 3)->default('in')->index();

            // Zuordnung: beim Einlesen der User, nach der Übernahme z. B. das Dokument
            $table->nullableMorphs('model');

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
            // Eingang im Postfach, bei gesendeten Nachrichten der Versand – danach sortieren Listen
            $table->timestamp('dated_at')->nullable()->index();

            $table->boolean('secure_channel')->nullable();
            $table->boolean('eeb_requested')->default(false);
            $table->string('integrity', 32)->nullable();      // complete | incomplete | missing
            $table->string('vhn_signature', 32)->nullable();  // valid | signature_ok | invalid | missing | notool | disabled

            $table->foreignId('imported_by')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable();
            // Anzahl der Anlagen in data.files (für die Listen); jede Datei der ZIP steht in data.files
            $table->unsignedSmallInteger('attachments_count')->default(0);
            $table->json('data')->nullable();
            $table->timestamps();
        });

        // Prüfhistorie: jede Prüfung von ZIP, Prüfsummen und Herkunftsnachweis
        Schema::create('bea_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bea_message_id')->constrained('bea_messages')->cascadeOnDelete();
            $table->string('trigger', 32);                      // ingest | manual | accept
            $table->boolean('archive_intact')->nullable();      // ZIP unverändert seit dem Einlesen
            $table->boolean('files_intact')->nullable();        // alle Dateien entsprechen den erfassten Prüfsummen
            $table->string('integrity', 32)->nullable();
            $table->string('vhn_signature', 32)->nullable();
            $table->json('result')->nullable();
            $table->foreignId('checked_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bea_verifications');
        Schema::dropIfExists('bea_messages');
    }
};
