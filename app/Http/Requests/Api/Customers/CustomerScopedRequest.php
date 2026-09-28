<?php

namespace App\Http\Requests\Api\Customers;

use App\Models\Customer;
use App\Models\Employee;
use App\Services\AccessControl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A request on one customer that needs `customers.manage` and the customer inside the actor's branch scope.
 * Both are checked before validation: 403 without the permission, 404 outside the scope.
 */
abstract class CustomerScopedRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(Gate::allows('customers.manage'), 403, 'You do not have permission to perform this action.');

        /** @var Employee $actor */
        $actor = $this->user();
        abort_unless(app(AccessControl::class)->scope(Customer::query(), $actor)->whereKey($this->customer()->id)->exists(), 404);

        return true;
    }

    public function customer(): Customer
    {
        /** @var Customer */
        return $this->route('customer');
    }
}
