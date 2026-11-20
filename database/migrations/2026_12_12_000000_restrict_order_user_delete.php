<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * orders.user_id used to cascade: deleting a customer deleted their orders, lines, work orders and
 * costs. Make the database refuse instead. MySQL only (SQLite, used by tests, cannot alter FKs);
 * the admin API guard (DeleteGuard::customer) covers both.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->replace('RESTRICT');
    }

    public function down(): void
    {
        $this->replace('CASCADE');
    }

    private function replace(string $onDelete): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $name = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'orders')
            ->where('COLUMN_NAME', 'user_id')
            ->where('REFERENCED_TABLE_NAME', 'users')
            ->value('CONSTRAINT_NAME');

        if ($name) {
            DB::statement("ALTER TABLE `orders` DROP FOREIGN KEY `{$name}`");
        }
        DB::statement("ALTER TABLE `orders` ADD CONSTRAINT `orders_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE {$onDelete}");
    }
};
