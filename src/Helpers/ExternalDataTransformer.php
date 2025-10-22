<?php

namespace Hrms\Shared\Helpers;

class ExternalDataTransformer
{
    /**
     * Transform external employee data to internal format
     */
    public function transformExternalEmployeeData(array $data): array
    {
        $transformed = [
            'employee_id' => InputSanitizer::sanitizeExternalId($data['external_employee_id'] ?? null),
            'full_name' => InputSanitizer::sanitizeString($data['full_name'] ?? null),
            'email' => InputSanitizer::sanitizeEmail($data['email'] ?? null),
            'phone' => InputSanitizer::sanitizePhone($data['phone_number'] ?? null),
            'position' => InputSanitizer::sanitizeString($data['role'] ?? null),
            'status' => array_key_exists('status', $data) ? ($data['status'] ? 'active' : 'terminated') : null,
            'external_tenant_id' => InputSanitizer::sanitizeExternalId($data['external_tenant_id'] ?? null),
            'external_employee_id' => InputSanitizer::sanitizeExternalId($data['external_employee_id'] ?? null),
            'external_user_id' => InputSanitizer::sanitizeExternalId($data['external_user_id'] ?? null),
            'external_branch_id' => InputSanitizer::sanitizeExternalId($data['external_branch_id'] ?? null),
            'external_ref_no' => InputSanitizer::sanitizeString($data['external_ref_no'] ?? null),
        ];

        // Add personal info if available
        if (isset($data['id_type']) || isset($data['id_number']) || isset($data['id_expiry_data']) || isset($data['gender']) || isset($data['country_code'])) {
            $transformed['personal_info'] = [
                'id_type' => InputSanitizer::sanitizeString($data['id_type'] ?? null),
                'id_number' => InputSanitizer::sanitizeString($data['id_number'] ?? null),
                'id_expiry_date' => InputSanitizer::sanitizeDate($data['id_expiry_data'] ?? null),
                'gender' => InputSanitizer::sanitizeString($data['gender'] ?? null),
                'country_code' => InputSanitizer::sanitizePhone($data['country_code'] ?? null),
            ];
        }

        // Remove nulls to avoid overwriting existing values unintentionally
        return array_filter($transformed, fn($v) => !is_null($v));
    }

    /**
     * Transform external attendance data
     */
    public function transformExternalAttendanceData(array $data): array
    {
        return [
            'date' => InputSanitizer::sanitizeDate($data['date'] ?? null),
            'time' => InputSanitizer::sanitizeTime($data['time'] ?? null),
            'location' => InputSanitizer::sanitizeString($data['location'] ?? null),
            'notes' => InputSanitizer::sanitizeString($data['notes'] ?? null),
            'check_in_method' => InputSanitizer::sanitizeString($data['check_in_method'] ?? 'external_api'),
            'check_out_method' => InputSanitizer::sanitizeString($data['check_out_method'] ?? 'external_api'),
            'location_data' => InputSanitizer::sanitizeLocationData($data['location_data'] ?? null),
        ];
    }

    /**
     * Extract external identifiers from data
     */
    public function extractExternalIdentifiers(array $data): array
    {
        return [
            'external_tenant_id' => InputSanitizer::sanitizeExternalId($data['external_tenant_id'] ?? null),
            'external_branch_id' => InputSanitizer::sanitizeExternalId($data['external_branch_id'] ?? null),
            'external_employee_id' => InputSanitizer::sanitizeExternalId($data['external_employee_id'] ?? null),
            'external_user_id' => InputSanitizer::sanitizeExternalId($data['external_user_id'] ?? null),
            'external_ref_no' => InputSanitizer::sanitizeString($data['external_ref_no'] ?? null),
        ];
    }
}
