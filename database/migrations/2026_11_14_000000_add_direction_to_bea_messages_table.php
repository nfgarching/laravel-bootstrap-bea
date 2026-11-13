<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Richtung der Nachricht (empfangen/gesendet) und maßgebliches Datum.
 *
 * - direction: „in“ (empfangen) oder „out“ (gesendet, erkannt am Journal
 *   „… bei versandten Nachrichten“ bzw. an der eigenen SAFE-ID des Absenders)
 * - dated_at: Eingang im Postfach, bei gesendeten Nachrichten der Versand –
 *   danach sortieren Listen und filtert das Jahr
 *
 * Bestehende gesendete Nachrichten: Aktenzeichen tauschen, damit
 * „aktenzeichen“ wie bei empfangenen das eigene ist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bea_messages', function (Blueprint $table) {
            $table->string('direction', 3)->default('in')->index()->after('status');
            $table->timestamp('dated_at')->nullable()->index()->after('intermediary_at');
        });

        $own = array_values(array_filter((array) config('bea.own_safe_ids', [])));

        DB::table('bea_messages')->orderBy('id')->chunkById(100, function ($rows) use ($own) {
            foreach ($rows as $row) {
                $data = json_decode((string) $row->data, true) ?: [];
                $journal = implode(' ', array_map(fn ($e) => (string) ($e['Ereignis'] ?? ''), $data['journal'] ?? []));

                $out = str_contains(mb_strtolower($journal), 'versandt')
                    || ($row->sender_safe_id && in_array($row->sender_safe_id, $own, true));

                $update = [
                    'direction' => $out ? 'out' : 'in',
                    'dated_at' => $out ? ($row->sent_at ?? $row->received_at) : ($row->received_at ?? $row->sent_at),
                ];

                if ($out) {
                    $update['aktenzeichen'] = $row->aktenzeichen_absender;
                    $update['aktenzeichen_absender'] = $row->aktenzeichen;
                    $data['direction'] = 'out';
                    [$data['aktenzeichen'], $data['aktenzeichen_absender']] = [$row->aktenzeichen_absender, $row->aktenzeichen];
                    $update['data'] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                DB::table('bea_messages')->where('id', $row->id)->update($update);
            }
        });
    }

    public function down(): void
    {
        // Aktenzeichen gesendeter Nachrichten zurücktauschen
        DB::table('bea_messages')->where('direction', 'out')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::table('bea_messages')->where('id', $row->id)->update([
                    'aktenzeichen' => $row->aktenzeichen_absender,
                    'aktenzeichen_absender' => $row->aktenzeichen,
                ]);
            }
        });

        Schema::table('bea_messages', function (Blueprint $table) {
            $table->dropIndex(['direction']);
            $table->dropIndex(['dated_at']);
            $table->dropColumn(['direction', 'dated_at']);
        });
    }
};
