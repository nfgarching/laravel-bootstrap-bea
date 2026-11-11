<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Eingelesene ZIP im Original auf der Disk „beas“
        Schema::create('bea_archives', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 64);
            $table->string('path', 1024);
            $table->string('original_name', 512);
            $table->string('sha256', 64)->unique();
            $table->unsignedBigInteger('size');
            $table->string('source', 32)->default('upload');   // upload | inbox | console
            $table->foreignId('imported_by')->nullable();
            $table->timestamps();
        });

        Schema::create('bea_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bea_archive_id')->nullable()->constrained('bea_archives')->nullOnDelete();
            $table->string('directory', 1024)->nullable();      // Ordner der Nachricht in der ZIP
            $table->string('status', 32)->default('ingested')->index(); // ingested | accepted

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
            $table->string('vhn_signature', 32)->nullable();  // valid | signature_ok | invalid | missing | notool | disabled

            $table->foreignId('imported_by')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
        });

        // Jede Datei der Nachricht in der ZIP – auch die, die nie übernommen werden
        Schema::create('bea_message_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bea_message_id')->constrained('bea_messages')->cascadeOnDelete();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete(); // gesetzt nach Übernahme
            $table->string('role', 32);                         // attachment | signature | meta
            $table->string('kind', 32)->nullable();             // bei meta: xjustiz, vhn, vhn_signature, osci, export …
            $table->string('name', 512);
            $table->string('zip_path', 1024);
            $table->string('display_name', 512)->nullable();
            $table->string('document_class', 16)->nullable();
            $table->string('category', 32)->nullable();         // verfuegung | schriftsatz | schreiben | anlage | sonstiges
            $table->boolean('suggested')->default(false);
            $table->boolean('signed')->default(false);          // abgesetzte Signatur (.p7s) liegt in der ZIP
            $table->string('signature_name', 512)->nullable();  // Name der Signaturdatei in der ZIP
            $table->string('signature_of', 512)->nullable();    // bei role=signature: signierte Datei
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('vhn_covered')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        // Zuordnungen (Akte, Namen …); die erste ist die Hauptzuordnung
        Schema::create('bea_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bea_message_id')->constrained('bea_messages')->cascadeOnDelete();
            $table->morphs('assignable');
            $table->foreignId('assigned_by')->nullable();
            $table->timestamps();
            $table->unique(['bea_message_id', 'assignable_type', 'assignable_id'], 'bea_assignments_unique');
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
        Schema::dropIfExists('bea_assignments');
        Schema::dropIfExists('bea_message_files');
        Schema::dropIfExists('bea_messages');
        Schema::dropIfExists('bea_archives');
    }
};
