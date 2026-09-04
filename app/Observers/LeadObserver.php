<?php

namespace App\Observers;

use App\Models\Activity;
use App\Models\Lead;

class LeadObserver
{
    /**
     * Handle the Lead "created" event.
     */
    public function created(Lead $lead): void
    {
        //
    }

    /**
     * Handle the Lead "updated" event.
     */
    public function updated(Lead $lead)
    {
        if ($lead->isDirty('status')) {
            Activity::create([
                'lead_id' => $lead->id,
                'user_id' => auth()->id(),
                'type' => 'status_change',
                'description' => "Статус изменен на: " . $lead->status,
                'properties' => ['old' => $lead->getOriginal('status'), 'new' => $lead->status]
            ]);
        }

        if ($lead->isDirty('price')) {
            Activity::create([
                'lead_id' => $lead->id,
                'user_id' => auth()->id(),
                'type' => 'system',
                'description' => "Цена изменена на " . $lead->price,
            ]);
        }
    }
    /**
     * Handle the Lead "deleted" event.
     */
    public function deleted(Lead $lead): void
    {
        //
    }

    /**
     * Handle the Lead "restored" event.
     */
    public function restored(Lead $lead): void
    {
        //
    }

    /**
     * Handle the Lead "force deleted" event.
     */
    public function forceDeleted(Lead $lead): void
    {
        //
    }
}
