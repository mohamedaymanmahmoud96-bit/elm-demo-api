<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportSqliteToMysql extends Command
{
    protected $signature = 'db:import-sqlite
                            {--source=sqlite_legacy : اتصال SQLite المصدر}
                            {--target=mysql : اتصال MySQL الهدف}
                            {--fresh : مسح جداول MySQL قبل النقل}';

    protected $description = 'نقل البيانات من database.sqlite إلى MySQL (بعد migrate على MySQL)';

    public function handle(): int
    {
        $source = (string) $this->option('source');
        $target = (string) $this->option('target');

        if (config("database.default") !== 'mysql' && $target === 'mysql') {
            $this->warn('تلميح: DB_CONNECTION في .env يفضّل أن يكون mysql أثناء التشغيل.');
        }

        try {
            DB::connection($source)->getPdo();
        } catch (\Throwable $e) {
            $this->error('تعذر فتح SQLite: '.$e->getMessage());
            $this->line('تأكد من وجود: '.database_path('database.sqlite'));

            return self::FAILURE;
        }

        try {
            DB::connection($target)->getPdo();
        } catch (\Throwable $e) {
            $this->error('تعذر الاتصال بـ MySQL: '.$e->getMessage());
            $this->line('شغّل MySQL وأنشئ قاعدة DB_DATABASE من phpMyAdmin ثم: php artisan migrate');

            return self::FAILURE;
        }

        $sqlitePath = config("database.connections.{$source}.database");
        $this->info("المصدر: {$sqlitePath}");
        $this->info('الهدف: '.config("database.connections.{$target}.database"));

        if ($this->option('fresh')) {
            if (! $this->confirm('سيتم مسح كل بيانات MySQL في هذا المشروع. متابعة؟', false)) {
                return self::SUCCESS;
            }
            $this->call('migrate:fresh', ['--force' => true]);
        } else {
            $this->call('migrate', ['--force' => true]);
        }

        $tables = collect(DB::connection($source)->select(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        ))->pluck('name')->all();

        if ($tables === []) {
            $this->warn('لا توجد جداول في SQLite.');

            return self::FAILURE;
        }

        DB::connection($target)->statement('SET FOREIGN_KEY_CHECKS=0');

        foreach ($tables as $table) {
            if (! Schema::connection($target)->hasTable($table)) {
                $this->warn("تخطي {$table} — غير موجود في MySQL (شغّل migrate).");

                continue;
            }

            $rows = DB::connection($source)->table($table)->get();
            DB::connection($target)->table($table)->truncate();

            if ($rows->isEmpty()) {
                $this->line("{$table}: 0 صف");

                continue;
            }

            $chunk = [];
            foreach ($rows as $row) {
                $chunk[] = (array) $row;
                if (count($chunk) >= 200) {
                    DB::connection($target)->table($table)->insert($chunk);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                DB::connection($target)->table($table)->insert($chunk);
            }

            $this->info("{$table}: {$rows->count()} صف");
        }

        DB::connection($target)->statement('SET FOREIGN_KEY_CHECKS=1');

        $this->newLine();
        $this->info('تم نقل البيانات إلى MySQL بنجاح.');
        $this->line('احتفظ بملف database.sqlite كنسخة احتياطية.');

        return self::SUCCESS;
    }
}
