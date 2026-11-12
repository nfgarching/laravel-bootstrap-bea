<?php

namespace Nfgarching\Bea\Controllers;

use App\Traits\MainControllerTrait;
use Illuminate\Database\Eloquent\Builder;
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
    use MainControllerTrait;

    /** @var array<string, string> */
    private const array STATUS_OPTIONS = [
        BeaMessage::STATUS_INGESTED => 'eingelesen',
        BeaMessage::STATUS_ACCEPTED => 'übernommen',
    ];

    /** @var array<string, string> */
    private const array ASSIGNED_OPTIONS = ['1' => 'zugeordnet', '0' => 'nicht zugeordnet'];

    /** @var array<string, string> */
    private const array CHECK_OPTIONS = ['ok' => 'ohne Beanstandung', 'problem' => 'mit Beanstandung'];

    public function index(Request $request): View
    {
        return $this->listing($request, popup: false);
    }

    /**
     * Die Liste als Popup (ohne Navigation), geöffnet z. B. aus einem Dokument:
     * model_type/model_id belegen die Zuordnung beim Einlesen vor und bleiben
     * beim Filtern, Sortieren und Blättern erhalten.
     */
    public function popup(Request $request): View
    {
        return $this->listing($request, popup: true);
    }

    private function listing(Request $request, bool $popup): View
    {
        $startTime = microtime(true);
        $this->defaultSort($request);

        $query = $this->filteredQuery($request)
            ->with(['assignments', 'latestVerification'])
            ->withCount([
                'messageFiles as attachments_count' => fn ($q) => $q->where('role', BeaMessageFile::ROLE_ATTACHMENT),
                'messageFiles as accepted_count' => fn ($q) => $q->whereNotNull('accepted_at'),
            ]);

        return view('bea::pages.index', [
            ...$this->context($request),
            'popup' => $popup,
            'listRoute' => $popup ? 'bea.popup' : 'bea.index',
            'messages' => $this->get_rows($query, $request),
            'request' => $request,
            'startTime' => $startTime,
            'jahre' => $this->jahre(),
            'statusOptions' => self::STATUS_OPTIONS,
            'assignedOptions' => self::ASSIGNED_OPTIONS,
            'checkOptions' => self::CHECK_OPTIONS,
            'pending' => BeaMessage::where('status', BeaMessage::STATUS_INGESTED)->count(),
        ]);
    }

    public function show(BeaMessage $message, Request $request, AssignTypes $types, BeaStorage $storage): View
    {
        $this->defaultSort($request);
        $message->load(['archive', 'messageFiles.file', 'assignments', 'verifications']);

        return view('bea::pages.show', [
            'message' => $message,
            'navigation' => $this->getNavigationData($message, $request, $this->filteredQuery($request)),
            'request' => $request,
            'files' => $message->messageFiles->sortBy([['role', 'asc'], ['name', 'asc']])->values(),
            'assignments' => $message->assignments->map(fn ($a) => $types->label($a->assignable_type, $a->assignable_id)),
            'archiveExists' => $message->archive ? $storage->exists($message->archive) : false,
            'tab' => $request->string('tab', 'nachricht')->toString(),
        ]);
    }

    /**
     * Nachrichten eingeschränkt auf die Filter der Liste (Zuordnung, Prüfung,
     * eEB, Jahr des Eingangs). Suche, Status (getFilterConditions()) und
     * Sortierung ergänzen get_rows()/getNavigationData() – so blättert show()
     * durch dieselbe Liste.
     *
     * @return Builder<BeaMessage>
     */
    private function filteredQuery(Request $request): Builder
    {
        return BeaMessage::query()
            ->when($request->jahr, fn ($q, $v) => $q->whereYear('received_at', (int) $v))
            ->when($request->input('assigned') === '1', fn ($q) => $q->has('assignments'))
            ->when($request->input('assigned') === '0', fn ($q) => $q->doesntHave('assignments'))
            ->when($request->input('check') === 'problem', fn ($q) => $q->where(fn ($w) => $w
                ->where('integrity', '!=', 'complete')
                ->orWhereNotIn('vhn_signature', ['valid', 'signature_ok'])
                ->orWhereHas('latestVerification', fn ($v) => $v->where(fn ($i) => $i->where('archive_intact', false)->orWhere('files_intact', false)))))
            ->when($request->input('check') === 'ok', fn ($q) => $q
                ->where('integrity', 'complete')
                ->whereIn('vhn_signature', ['valid', 'signature_ok']))
            ->when($request->boolean('eeb'), fn ($q) => $q->where('eeb_requested', true));
    }

    /**
     * Ohne ?sort= neueste Eingänge zuerst (statt nach ID wie im Trait).
     */
    private function defaultSort(Request $request): void
    {
        $request->mergeIfMissing(['sort' => 'received_at', 'direction' => 'desc']);
    }

    /**
     * Prüft ZIP, Dateien und Herkunftsnachweis erneut gegen das Original.
     */
    public function verify(BeaMessage $message, Request $request, BeaIntegrityChecker $checker): RedirectResponse
    {
        $verification = $checker->check($message);

        // Listenfilter der Detailseite (Blättern) mitnehmen, Tab „Prüfung“ öffnen
        return redirect()
            ->route('bea.show', ['message' => $message, ...$request->except(['_token', 'tab']), 'tab' => 'pruefung'])
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
     * Jahre mit Nachrichten, neuestes zuerst (Jahr => Jahr).
     *
     * @return array<int, int>
     */
    private function jahre(): array
    {
        $min = BeaMessage::min('received_at');
        $max = BeaMessage::max('received_at');
        if (! $min || ! $max) {
            return [];
        }

        $jahre = range((int) substr((string) $max, 0, 4), (int) substr((string) $min, 0, 4));

        return array_combine($jahre, $jahre);
    }
}
