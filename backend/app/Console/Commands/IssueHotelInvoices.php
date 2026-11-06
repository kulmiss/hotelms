<?php

namespace App\Console\Commands;

use App\Services\HotelBilling;
use Illuminate\Console\Command;

/** Daily: subscription invoices for hotels whose paid period or trial ends within a week (emailed). */
class IssueHotelInvoices extends Command
{
    protected $signature = 'subscriptions:invoices';

    protected $description = 'Make and email subscription invoices that are due';

    public function handle(HotelBilling $billing): int
    {
        $this->info('Invoices made: '.$billing->issueDue());

        return self::SUCCESS;
    }
}
