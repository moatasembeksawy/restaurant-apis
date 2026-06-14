<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\PrintJob;

use App\Modules\POS\Billing\Models\Payment;
use App\Modules\POS\Orders\Models\Order;
use App\Modules\Tenant\Models\Branch;
use App\Modules\Tenant\Models\Tenant;

/**
 * Builds raw ESC/POS byte strings for thermal printers.
 * Frontend print bridges send these bytes directly to USB/network printers.
 */
class EscPosBuilder
{
    private const ESC = "\x1B";

    private const GS = "\x1D";

    private string $buffer = '';

    public function reset(): self
    {
        $this->buffer = self::ESC.'@';

        return $this;
    }

    public function alignCenter(): self
    {
        $this->buffer .= self::ESC.'a'.chr(1);

        return $this;
    }

    public function alignLeft(): self
    {
        $this->buffer .= self::ESC.'a'.chr(0);

        return $this;
    }

    public function bold(bool $on = true): self
    {
        $this->buffer .= self::ESC.'E'.chr($on ? 1 : 0);

        return $this;
    }

    public function text(string $line): self
    {
        $this->buffer .= $line."\n";

        return $this;
    }

    public function separator(): self
    {
        $this->buffer .= str_repeat('-', 32)."\n";

        return $this;
    }

    public function cut(): self
    {
        $this->buffer .= self::GS.'V'.chr(0);

        return $this;
    }

    public function build(): string
    {
        return $this->buffer;
    }

    public function buildKitchenTicket(Order $order, Branch $branch): string
    {
        $this->reset()->alignCenter()->bold()->text('KITCHEN TICKET')->bold(false)
            ->text($branch->name_ar ?? $branch->name)
            ->separator()->alignLeft();

        if ($order->table) {
            $this->text('Table: '.$order->table->name);
        }

        $this->text('Order #'.$order->id)
            ->text('Channel: '.$order->channel)
            ->separator();

        foreach ($order->items as $item) {
            $this->bold()->text("{$item->quantity}x {$item->item_name_ar}")->bold(false);
            if ($item->notes) {
                $this->text('  Note: '.$item->notes);
            }
        }

        if ($order->notes) {
            $this->separator()->text('Order note: '.$order->notes);
        }

        return $this->separator()->cut()->build();
    }

    public function buildReceipt(Order $order, Payment $payment, Tenant $tenant, Branch $branch): string
    {
        $this->reset()->alignCenter()->bold()->text($tenant->name)->bold(false)
            ->text($branch->name_ar ?? $branch->name)
            ->text($branch->address ?? '')
            ->separator()->alignLeft()
            ->text('Order #'.$order->id)
            ->text('Date: '.$payment->created_at->format('Y-m-d H:i'))
            ->separator();

        foreach ($order->items as $item) {
            $line = sprintf(
                '%dx %s %s EGP',
                $item->quantity,
                $item->item_name_ar,
                number_format((float) $item->subtotal, 2),
            );
            $this->text($line);
        }

        $this->separator()
            ->bold()->text('Total: '.number_format((float) $order->total, 2).' EGP')->bold(false)
            ->text('Paid via: '.$payment->method);

        if ($payment->change_due !== null && (float) $payment->change_due > 0) {
            $this->text('Change: '.number_format((float) $payment->change_due, 2).' EGP');
        }

        $invoice = $payment->invoice;
        if ($invoice?->eta_uuid) {
            $this->separator()->text('ETA UUID: '.$invoice->eta_uuid);
        }

        return $this->separator()->alignCenter()->text('Thank you!')->cut()->build();
    }
}
