<?php

namespace App\Console\Commands;

use App\Services\Billing\SubscriptionLifecycle;
use Illuminate\Console\Command;

class NotifySubscriptionLifecycle extends Command
{
    protected $signature = 'billing:lifecycle';

    protected $description = 'Avisa a las empresas cuya prueba (o plan por cortesía) termina pronto o ya terminó';

    public function handle(SubscriptionLifecycle $lifecycle): int
    {
        $sent = $lifecycle->run();

        $this->info("Avisos de fin próximo: {$sent['ending']} · avisos de fin: {$sent['ended']}");

        return self::SUCCESS;
    }
}
