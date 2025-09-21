<?php

namespace Shared\Commands;

use Illuminate\Console\Command;
use Shared\Services\SecurityAuditService;

class SecurityAuditCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:audit 
                            {--detailed : Show detailed audit results}
                            {--export= : Export results to file (json|csv)}
                            {--fix : Attempt to fix critical issues}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform comprehensive security audit of the HRMS microservices';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔒 Starting Security Audit...');
        $this->newLine();

        $auditService = app(SecurityAuditService::class);
        $auditResults = $auditService->performSecurityAudit();

        $this->displayAuditResults($auditResults);

        if ($this->option('export')) {
            $this->exportResults($auditResults);
        }

        if ($this->option('fix')) {
            $this->attemptFixes($auditResults);
        }

        $this->newLine();
        $this->info('✅ Security audit completed!');
    }

    /**
     * Display audit results
     */
    private function displayAuditResults(array $auditResults): void
    {
        // Overall status
        $statusColor = $this->getStatusColor($auditResults['status']);
        $this->line("Overall Security Score: <{$statusColor}>{$auditResults['overall_score']}%</{$statusColor}>");
        $this->line("Security Status: <{$statusColor}>{$auditResults['status']}</{$statusColor}>");
        $this->newLine();

        // Summary
        $this->info('📊 Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Checks', $auditResults['total_checks']],
                ['Passed', $auditResults['passed_checks']],
                ['Warnings', $auditResults['warnings']],
                ['Failed', $auditResults['failed_checks']],
                ['Critical Issues', count($auditResults['critical_issues'])],
            ]
        );

        // Critical issues
        if (!empty($auditResults['critical_issues'])) {
            $this->newLine();
            $this->error('🚨 Critical Issues:');
            foreach ($auditResults['critical_issues'] as $issue) {
                $this->line("  • {$issue}");
            }
        }

        // Detailed results
        if ($this->option('detailed')) {
            $this->displayDetailedResults($auditResults);
        }

        // Recommendations
        if (!empty($auditResults['recommendations'])) {
            $this->newLine();
            $this->warn('💡 Recommendations:');
            foreach (array_unique($auditResults['recommendations']) as $recommendation) {
                $this->line("  • {$recommendation}");
            }
        }
    }

    /**
     * Display detailed audit results
     */
    private function displayDetailedResults(array $auditResults): void
    {
        $this->newLine();
        $this->info('📋 Detailed Results:');

        foreach ($auditResults['checks'] as $checkName => $result) {
            $statusIcon = $this->getStatusIcon($result['status']);
            $statusColor = $this->getStatusColor($result['status']);
            
            $this->line("{$statusIcon} <{$statusColor}>{$checkName}</{$statusColor}> ({$result['score']}%)");
            $this->line("   {$result['message']}");
            
            if (!empty($result['issues'])) {
                foreach ($result['issues'] as $issue) {
                    $this->line("   ⚠️  {$issue}");
                }
            }
            
            $this->newLine();
        }
    }

    /**
     * Export results to file
     */
    private function exportResults(array $auditResults): void
    {
        $format = $this->option('export');
        $filename = 'security_audit_' . date('Y-m-d_H-i-s') . '.' . $format;

        switch ($format) {
            case 'json':
                file_put_contents($filename, json_encode($auditResults, JSON_PRETTY_PRINT));
                break;
            case 'csv':
                $this->exportToCsv($auditResults, $filename);
                break;
            default:
                $this->error("Unsupported export format: {$format}");
                return;
        }

        $this->info("📄 Results exported to: {$filename}");
    }

    /**
     * Export to CSV format
     */
    private function exportToCsv(array $auditResults, string $filename): void
    {
        $file = fopen($filename, 'w');
        
        // Header
        fputcsv($file, ['Check Name', 'Status', 'Score', 'Message', 'Issues', 'Recommendations']);
        
        // Data
        foreach ($auditResults['checks'] as $checkName => $result) {
            fputcsv($file, [
                $checkName,
                $result['status'],
                $result['score'],
                $result['message'],
                implode('; ', $result['issues']),
                implode('; ', $result['recommendations'])
            ]);
        }
        
        fclose($file);
    }

    /**
     * Attempt to fix critical issues
     */
    private function attemptFixes(array $auditResults): void
    {
        $this->newLine();
        $this->info('🔧 Attempting to fix critical issues...');

        $fixedCount = 0;
        foreach ($auditResults['critical_issues'] as $issue) {
            if ($this->fixIssue($issue)) {
                $fixedCount++;
                $this->line("✅ Fixed: {$issue}");
            } else {
                $this->line("❌ Could not fix: {$issue}");
            }
        }

        $this->info("Fixed {$fixedCount} out of " . count($auditResults['critical_issues']) . " critical issues.");
    }

    /**
     * Fix specific issue
     */
    private function fixIssue(string $issue): bool
    {
        // This would contain actual fix logic
        // For now, just return false as we don't want to make automatic changes
        return false;
    }

    /**
     * Get status color for output
     */
    private function getStatusColor(string $status): string
    {
        return match($status) {
            'excellent' => 'green',
            'good' => 'green',
            'fair' => 'yellow',
            'poor' => 'yellow',
            'critical' => 'red',
            'pass' => 'green',
            'warning' => 'yellow',
            'fail' => 'red',
            default => 'white'
        };
    }

    /**
     * Get status icon
     */
    private function getStatusIcon(string $status): string
    {
        return match($status) {
            'pass' => '✅',
            'warning' => '⚠️',
            'fail' => '❌',
            default => 'ℹ️'
        };
    }
}
