<?php

namespace App\Services\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Models\Medicine;
use App\Models\MedicineCategory;
use App\Models\User;
use App\Notifications\CatalogItemReviewed;
use App\Notifications\CatalogItemSubmitted;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Medicines and categories added by pharmacies / hospitals: usable by their own organization at once,
 * shared with everyone only after the platform team approves them.
 */
class CatalogContributionService
{
    public function __construct(private ActivityLogger $logger) {}

    public function createMedicine(User $user, array $data): Medicine
    {
        $this->guardDuplicate($user, $data);

        if ($user->can('catalog.manage')) {
            return Medicine::create($data + ['review_status' => 'approved']);
        }

        $medicine = DB::transaction(fn () => Medicine::create([
            ...$data,
            'is_active' => true,
            'created_by_tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
            'review_status' => 'pending',
        ]));
        $this->notifyPlatform($medicine, $user);

        return $medicine;
    }

    public function updateMedicine(User $user, Medicine $medicine, array $data): Medicine
    {
        $this->guardDuplicate($user, $data, $medicine->id);
        if (! $user->can('catalog.manage')) {
            // A corrected submission goes back into the review queue
            $wasRejected = $medicine->review_status === 'rejected';
            $data = [...$data, 'review_status' => 'pending', 'review_note' => null];
            $medicine->update($data);
            if ($wasRejected) {
                $this->notifyPlatform($medicine, $user);
            }

            return $medicine;
        }
        $medicine->update($data);

        return $medicine;
    }

    public function createCategory(User $user, array $data): MedicineCategory
    {
        if ($user->can('catalog.manage')) {
            return MedicineCategory::create($data + ['review_status' => 'approved']);
        }
        $category = MedicineCategory::create([...$data, 'created_by_tenant_id' => $user->tenant_id, 'review_status' => 'pending']);
        $this->notifyPlatform($category, $user);

        return $category;
    }

    /** Platform team decision. Approving a medicine also approves its (pending) category. */
    public function review(Medicine|MedicineCategory $item, bool $approve, ?string $note, User $reviewer): Medicine|MedicineCategory
    {
        if (! $approve && ! $note) {
            throw BusinessRuleException::withErrors('note', 'Give the reason so the pharmacy can correct it.');
        }

        DB::transaction(function () use ($item, $approve, $note) {
            $attributes = ['review_status' => $approve ? 'approved' : 'rejected', 'review_note' => $approve ? null : $note];
            if ($item instanceof Medicine) {
                $attributes['reviewed_at'] = now();
            }
            $item->update($attributes);

            if ($approve && $item instanceof Medicine && $item->category && $item->category->review_status !== 'approved') {
                $item->category->update(['review_status' => 'approved', 'review_note' => null]);
            }
        });

        $this->logger->log($approve ? 'catalog_approved' : 'catalog_rejected', $item, ['note' => $note]);
        $item->contributor?->users()->where('status', 'active')->get()
            ->filter(fn (User $u) => $u->can('catalog.contribute'))
            ->each->notify(new CatalogItemReviewed($item->refresh()));

        return $item;
    }

    /** Same generic name, strength and form already visible to this user: use that one instead. */
    private function guardDuplicate(User $user, array $data, ?int $ignoreId = null): void
    {
        $existing = Medicine::visibleTo($user)
            ->whereRaw('lower(generic_name) = ?', [mb_strtolower(trim($data['generic_name']))])
            ->whereRaw('lower(strength) = ?', [mb_strtolower(trim($data['strength']))])
            ->whereRaw('lower(dosage_form) = ?', [mb_strtolower(trim($data['dosage_form']))])
            ->when($data['brand_name'] ?? null, fn ($q, $brand) => $q->whereRaw('lower(coalesce(brand_name, \'\')) = ?', [mb_strtolower(trim($brand))]))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first();

        if ($existing) {
            throw BusinessRuleException::withErrors('generic_name', "{$existing->displayName()} ({$existing->dosage_form}) is already in the catalogue - add that one to your inventory instead.");
        }
    }

    private function notifyPlatform(Medicine|MedicineCategory $item, User $user): void
    {
        $organization = $user->tenant?->name ?? $user->name;
        $this->logger->log('catalog_submitted', $item, ['organization' => $organization]);
        User::role(User::ROLE_SUPER_ADMIN)->where('status', 'active')->get()->each->notify(new CatalogItemSubmitted($item, $organization));
    }
}
