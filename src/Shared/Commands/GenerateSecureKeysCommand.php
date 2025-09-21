<?php

namespace Shared\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class GenerateSecureKeysCommand extends Command
{
    protected $signature = 'security:generate-keys {--output= : Output file for keys}';
    protected $description = 'Generate secure keys for the HRMS system';

    public function handle(): int
    {
        $this->info('Generating secure keys for HRMS system...');

        $keys = [
            'APP_KEY' => 'base64:' . base64_encode(random_bytes(32)),
            'JWT_SECRET' => Str::random(64),
            'ENCRYPTION_KEY' => base64_encode(random_bytes(32)),
            'DB_PASSWORD' => Str::random(32),
            'REDIS_PASSWORD' => Str::random(32),
            'KONG_PG_PASSWORD' => Str::random(32),
            'IDENTITY_API_KEY' => 'hrms_' . Str::random(60),
            'EMPLOYEE_API_KEY' => 'hrms_' . Str::random(60),
            'ATTENDANCE_API_KEY' => 'hrms_' . Str::random(60),
            'SMTP_PASSWORD' => Str::random(32),
        ];

        if ($outputFile = $this->option('output')) {
            $this->writeKeysToFile($keys, $outputFile);
        } else {
            $this->displayKeys($keys);
        }

        $this->info('Secure keys generated successfully!');
        return 0;
    }

    private function displayKeys(array $keys): void
    {
        $this->line('');
        $this->line('Generated Secure Keys:');
        $this->line('=====================');

        foreach ($keys as $key => $value) {
            $this->line("{$key}={$value}");
        }

        $this->line('');
        $this->warn('IMPORTANT: Store these keys securely and never commit them to version control!');
    }

    private function writeKeysToFile(array $keys, string $outputFile): void
    {
        $content = "# HRMS Secure Keys - Generated on " . now()->toISOString() . "\n";
        $content .= "# DO NOT COMMIT THIS FILE TO VERSION CONTROL!\n\n";

        foreach ($keys as $key => $value) {
            $content .= "{$key}={$value}\n";
        }

        File::put($outputFile, $content);
        $this->info("Keys written to: {$outputFile}");
    }
}
