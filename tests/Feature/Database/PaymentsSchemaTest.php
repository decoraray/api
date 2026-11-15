<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_payments_has_payment_code_and_card_pan()
    {
        $this->assertTrue(Schema::hasColumn('payments', 'payment_code'));
        $this->assertTrue(Schema::hasColumn('payments', 'card_pan'));
    }
}
