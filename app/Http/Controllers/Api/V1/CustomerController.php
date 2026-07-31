<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        abort_unless(
            request()->user()?->hasAnyRole(['admin', 'sales_staff']),
            403
        );

        $customers = Customer::query()
            ->when(request('type'), fn ($q, $type) => $q->where('type', $type))
            ->when(request('search'), fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(min((int) request('per_page', 50), 100));

        return CustomerResource::collection($customers);
    }

    public function show(Customer $customer): CustomerResource
    {
        abort_unless(
            request()->user()?->hasAnyRole(['admin', 'sales_staff']),
            403
        );

        return new CustomerResource($customer);
    }
}
