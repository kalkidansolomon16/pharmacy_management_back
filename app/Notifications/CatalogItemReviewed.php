<?php

namespace App\Notifications;

use App\Models\Medicine;
use App\Models\MedicineCategory;

/** To the organization that submitted it: the platform team approved or rejected a catalogue entry. */
class CatalogItemReviewed extends AppNotification
{
    public function __construct(public Medicine|MedicineCategory $item)
    {
        parent::__construct();
    }

    protected function payload(object $notifiable): array
    {
        $medicine = $this->item instanceof Medicine;
        $name = $medicine ? $this->item->displayName() : $this->item->name;
        $approved = $this->item->review_status === 'approved';

        return [
            'kind' => 'catalog_reviewed',
            'level' => $approved ? 'success' : 'warning',
            'title' => $approved ? "“{$name}” is now in the shared catalogue" : "“{$name}” was not approved",
            'message' => $approved
                ? 'Every pharmacy and doctor on MedLink can now use it.'
                : 'Reason: '.($this->item->review_note ?: 'not given').'. You can still use it; edit it to resubmit.',
            'link' => '/app/catalog',
            'meta' => [$medicine ? 'medicine_id' : 'category_id' => $this->item->id],
        ];
    }
}
