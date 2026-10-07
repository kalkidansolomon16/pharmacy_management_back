<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /** Pharmacy order queue. */
    public function viewAny(User $user): bool
    {
        return $user->can('orders.view');
    }

    public function view(User $user, Order $order): bool
    {
        return $order->user_id === $user->id
            || ($user->can('orders.view') && $user->belongsToTenant($order->tenant_id));
    }

    public function place(User $user): bool
    {
        return $user->can('orders.place');
    }

    /** Confirm / ready / complete / reject. */
    public function process(User $user, Order $order): bool
    {
        return $user->can('orders.manage') && $user->belongsToTenant($order->tenant_id);
    }

    /** Customers may withdraw their own order until the pharmacy has confirmed it. */
    public function cancel(User $user, Order $order): bool
    {
        if ($this->process($user, $order)) {
            return true;
        }

        return $order->user_id === $user->id && $order->status === 'pending';
    }

    public function sell(User $user): bool
    {
        return $user->can('sales.create');
    }
}
