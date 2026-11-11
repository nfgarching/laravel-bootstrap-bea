<?php

namespace Nfgarching\Bea\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Nfgarching\Bea\Models\BeaMessage;
use Nfgarching\Bea\Models\BeaMessageFile;
use Nfgarching\Bea\Services\AssignTypes;
use Nfgarching\Bea\Services\BeaIntegrityChecker;
use Nfgarching\Bea\Services\BeaStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Eingelesene beA-Nachrichten: Liste, Detail mit Prüfung und Verlauf,
 * erneute Prüfung, Download der Original-ZIP.
 */
class BeaController
{
    public function index(Request $request): View
    {
        $messages = BeaMessage::query()
            ->with(['assignments', 'latestVerification'])
            ->withCount([
                'messageFiles as attachments_count' => fn ($q) => $q->where('role', BeaMessageFile::ROLE_ATTACHMENT),
                'messageFiles as accepted_count' => fn ($q) => $q->whereNotNull('accepted_at'),
            ])
            ->search($request->string('search')->toString())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('jahr'), fn ($q) => $q->whereYear('received_at', $request->integer('jahr')))
            ->when($request->input('assigned') === '1', fn ($q) => $q->has('assignments'))
            ->when($request->input('assigned') === '0', fn ($q) => $q->doesntHave('assignments'))
            ->when($request->input('check') === 'problem', fn ($q) => $q->where(fn ($w) => $w
                ->where('integrity', '!=', 'complete')
                ->orWhereNotIn('vhn_signature', ['valid', 'signature_ok'])
                ->orWhereHas('latestVerification', fn ($v) => $v->where(fn ($i) => $i->where('archive_intact', false)->orWhere('files_intact', false)))))
            ->when($request->input('check') === 'ok', fn ($q) => $q
                ->where('integrity', 'complete')
                ->whereIn('vhn_signature', ['valid', 'signature_ok']))
            ->when($request->boolean('eeb'), fn ($q) => $q->where('eeb_requested', true))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return view('bea::pages.index', [
            'messages' => $messages,
            'request' => $request,
            'jahre' => $this->jahre(),
            'pending' => BeaMessage::where('status', BeaMessage::STATUS_INGESTED)->count(),
        ]);
    }

    public function show(BeaMessage $message, Request $request, AssignTypes $types, BeaStorage $storage): View
    {
        $message->load(['archive', 'messageFiles.file', 'assignments', 'verifications']);

        return view('bea::pages.show', [
            'message' => $message,
            'files' => $message->messageFiles->sortBy([['role', 'asc'], ['name', 'asc']])->values(),
            'assignments' => $message->assignments->map(fn ($a) => $types->label($a->assignable_type, $a->assignable_id)),
            'archiveExists' => $message->archive ? $storage->exists($message->archive) : false,
            'tab' => $request->string('tab', 'nachricht')->toString(),
        ]);
    }

    /**
     * Prüft ZIP, Dateien und Herkunftsnachweis erneut gegen das Original.
     */
    public function verify(BeaMessage $message, BeaIntegrityChecker $checker): RedirectResponse
    {
        $verification = $checker->check($message);

        return redirect()
            ->route('bea.show', ['message' => $message, 'tab' => 'pruefung'])
            ->with('message', $verification->passed()
                ? 'Prüfung ohne Beanstandung: ZIP und Dateien unverändert.'
                : 'Prüfung mit Beanstandungen – Einzelheiten im Verlauf.');
    }

    /**
     * Original-ZIP herunterladen.
     */
    public function archive(BeaMessage $message, BeaStorage $storage): StreamedResponse
    {
        $archive = $message->archive;
        abort_unless($archive && $storage->exists($archive), 404, 'Original-ZIP nicht gefunden.');

        return Storage::disk($archive->disk)->download($archive->path, $archive->original_name);
    }

    public function import(Request $request): View
    {
        return view('bea::pages.import', $this->context($request));
    }

    public function upload(Request $request): View
    {
        return view('bea::pages.upload', $this->context($request));
    }

    public function select(BeaMessage $message): View
    {
        return view('bea::pages.select', ['message' => $message]);
    }

    /**
     * Aufruf aus einer Akte, einem Dokument oder Namen heraus: Zuordnung vorbelegen.
     *
     * @return array{modelType: ?string, modelId: ?int}
     */
    private function context(Request $request): array
    {
        return [
            'modelType' => $request->filled('model_type') ? $request->string('model_type')->toString() : null,
            'modelId' => $request->integer('model_id') ?: null,
        ];
    }

    /**
     * Jahre mit Nachrichten, neuestes zuerst.
     *
     * @return list<int>
     */
    private function jahre(): array
    {
        $min = BeaMessage::min('received_at');
        $max = BeaMessage::max('received_at');
        if (! $min || ! $max) {
            return [];
        }

        return range((int) substr((string) $max, 0, 4), (int) substr((string) $min, 0, 4));
    }
}
