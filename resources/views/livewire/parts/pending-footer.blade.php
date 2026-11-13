<button type="button" class="btn btn-sm btn-primary text-nowrap" wire:click="importMessages"
    wire:loading.attr="disabled" wire:target="importMessages" @disabled(empty($selectedMessages))>
    <i class="bi bi-download me-1"></i>
    <span wire:loading.remove wire:target="importMessages">Übernehmen ({{ count($selectedMessages) }})</span>
    <span wire:loading wire:target="importMessages">Wird übernommen…</span>
</button>
