<?php

namespace Hrms\Shared\Helpers;

class InputSanitizer
{
    /**
     * Sanitize string input
     */
    public static function sanitizeString(?string $input, int $maxLength = 255): ?string
    {
        if ($input === null) {
            return null;
        }

        // Remove null bytes and control characters
        $input = str_replace(["\0", "\x00"], '', $input);
        
        // Trim whitespace
        $input = trim($input);
        
        // Limit length
        if (strlen($input) > $maxLength) {
            $input = substr($input, 0, $maxLength);
        }
        
        return $input;
    }

    /**
     * Sanitize email input
     */
    public static function sanitizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = self::sanitizeString($email, 320); // RFC 5321 limit
        return filter_var($email, FILTER_SANITIZE_EMAIL) ?: null;
    }

    /**
     * Sanitize phone number input
     */
    public static function sanitizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        // Remove all non-digit characters except + at the beginning
        $phone = preg_replace('/[^\d+]/', '', $phone);
        
        // Ensure + is only at the beginning
        if (strpos($phone, '+') > 0) {
            $phone = str_replace('+', '', $phone);
        }
        
        return self::sanitizeString($phone, 20);
    }

    /**
     * Sanitize external ID input
     */
    public static function sanitizeExternalId(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }

        // Remove potentially dangerous characters but keep alphanumeric, hyphens, underscores
        $id = preg_replace('/[^a-zA-Z0-9\-_]/', '', $id);
        
        return self::sanitizeString($id, 100);
    }

    /**
     * Sanitize numeric input
     */
    public static function sanitizeNumeric(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        // Keep only digits and decimal point
        $input = preg_replace('/[^\d.]/', '', $input);
        
        return self::sanitizeString($input, 20);
    }

    /**
     * Sanitize date input
     */
    public static function sanitizeDate(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }

        $date = self::sanitizeString($date, 10);
        
        // Validate date format (YYYY-MM-DD)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        
        return null;
    }

    /**
     * Sanitize time input
     */
    public static function sanitizeTime(?string $time): ?string
    {
        if ($time === null) {
            return null;
        }

        $time = self::sanitizeString($time, 8);
        
        // Validate time format (HH:MM:SS or HH:MM)
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
            return $time;
        }
        
        return null;
    }

    /**
     * Sanitize array of strings
     */
    public static function sanitizeStringArray(array $array, int $maxLength = 255): array
    {
        return array_map(function ($item) use ($maxLength) {
            return self::sanitizeString($item, $maxLength);
        }, $array);
    }

    /**
     * Sanitize schedule data
     */
    public static function sanitizeSchedule(array $schedule): array
    {
        $sanitized = [];
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        
        foreach ($days as $day) {
            if (isset($schedule[$day]) && is_array($schedule[$day])) {
                $daySchedule = $schedule[$day];
                $sanitized[$day] = [
                    'open' => self::sanitizeTime($daySchedule['open'] ?? null),
                    'close' => self::sanitizeTime($daySchedule['close'] ?? null),
                ];
            }
        }
        
        return $sanitized;
    }

    /**
     * Sanitize location data
     */
    public static function sanitizeLocationData(?array $locationData): ?array
    {
        if ($locationData === null) {
            return null;
        }

        return [
            'lat' => isset($locationData['lat']) ? (float) $locationData['lat'] : null,
            'lng' => isset($locationData['lng']) ? (float) $locationData['lng'] : null,
        ];
    }
}
