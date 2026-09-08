<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Customers\Services;

use App\Modules\Delivery\Customers\Models\Customer;
use App\Modules\Delivery\Customers\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CustomerAddressService
{
    /**
     * @param  array{
     *     district_id?: int|null,
     *     label?: string|null,
     *     address: string,
     *     is_default?: bool
     * }  $data
     */
    public function create(Customer $customer, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($customer, $data): CustomerAddress {
            $isDefault = (bool) ($data['is_default'] ?? $customer->addresses()->doesntExist());

            $address = $customer->addresses()->create([
                'district_id' => $data['district_id'] ?? null,
                'label' => $data['label'] ?? null,
                'address' => $data['address'],
                'is_default' => $isDefault,
            ]);

            if ($isDefault) {
                $this->syncDefault($customer, $address);
            }

            return $address->load('district');
        });
    }

    /**
     * @param  array{
     *     district_id?: int|null,
     *     label?: string|null,
     *     address?: string,
     *     is_default?: bool
     * }  $data
     */
    public function update(Customer $customer, CustomerAddress $address, array $data): CustomerAddress
    {
        $this->assertBelongsToCustomer($customer, $address);

        return DB::transaction(function () use ($customer, $address, $data): CustomerAddress {
            $address->update($data);

            if (array_key_exists('is_default', $data) && $data['is_default']) {
                $this->syncDefault($customer, $address);
            } elseif ($address->is_default) {
                $this->syncCustomerDefaultAddress($customer, $address);
            }

            if ($customer->addresses()->where('is_default', true)->doesntExist()) {
                $this->syncDefault($customer, $address->fresh() ?? $address);
            }

            return $address->fresh('district') ?? $address;
        });
    }

    public function delete(Customer $customer, CustomerAddress $address): void
    {
        $this->assertBelongsToCustomer($customer, $address);

        DB::transaction(function () use ($customer, $address): void {
            $wasDefault = $address->is_default;
            $address->delete();

            if (! $wasDefault) {
                return;
            }

            $next = $customer->addresses()->orderByDesc('id')->first();

            if ($next) {
                $this->syncDefault($customer, $next);
            } else {
                $customer->update(['default_address' => null]);
            }
        });
    }

    public function syncFromDefaultAddressString(Customer $customer, ?string $addressText): void
    {
        if ($addressText === null || trim($addressText) === '') {
            return;
        }

        $default = $customer->addresses()->where('is_default', true)->first()
            ?? $customer->addresses()->orderBy('id')->first();

        if ($default !== null) {
            $default->update(['address' => $addressText]);
            $customer->update(['default_address' => $addressText]);

            return;
        }

        $this->create($customer, [
            'address' => $addressText,
            'is_default' => true,
        ]);
    }

    public function assertBelongsToCustomer(Customer $customer, CustomerAddress $address): void
    {
        if ((int) $address->customer_id !== (int) $customer->id) {
            throw new InvalidArgumentException('Address does not belong to this customer.');
        }
    }

    public function syncDefault(Customer $customer, ?CustomerAddress $address): void
    {
        if ($address === null) {
            $customer->update(['default_address' => null]);

            return;
        }

        $customer->addresses()
            ->whereKeyNot($address->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        if (! $address->is_default) {
            $address->update(['is_default' => true]);
        }

        $this->syncCustomerDefaultAddress($customer, $address);
    }

    private function syncCustomerDefaultAddress(Customer $customer, CustomerAddress $address): void
    {
        $customer->update(['default_address' => $address->address]);
    }
}
