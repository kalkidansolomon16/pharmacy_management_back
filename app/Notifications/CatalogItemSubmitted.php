<?php

namespace App\Notifications;

use App\Models\Medicine;
use App\Models\MedicineCategory;

/** To the platform team: a pharmacy / hospital added a medicine or category that needs review. */
class CatalogItemSubmitted extends AppNotification
{
    public function __construct(public Medicine|MedicineCategory $item, public string $organization)
    {
        parent::__construct();
    }

    protected function payload(object $notifiable): array
    {
        $medicine = $this->item instanceof Medicine;
        $name = $medicine ? $this->item->displayName() : $this->item->name;

        return [
            'kind' => 'catalog_submitted',
            'level' => 'info',
            'title' => ($medicine ? 'New medicine' : 'New category').' to review',
            'message' => "{$this->organization} added “{$name}”. Approve it to share it with every pharmacy.",
            'link' => '/app/admin/catalog?review=pending',
            'meta' => [$medicine ? 'medicine_id' : 'category_id' => $this->item->id],
        ];
    }
}
