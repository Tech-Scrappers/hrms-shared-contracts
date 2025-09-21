<?php

namespace Shared\Helpers;

class ValidationHelper
{
    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    public static function validatePhone(string $phone): bool
    {
        // Basic phone validation - can be enhanced based on requirements
        return preg_match('/^[\+]?[1-9][\d]{0,15}$/', $phone);
    }
    
    public static function validatePassword(string $password): array
    {
        $errors = [];
        
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }
        
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }
        
        return $errors;
    }
    
    public static function sanitizeInput(string $input): string
    {
        // Remove null bytes
        $input = str_replace("\0", '', $input);
        
        // Trim whitespace
        $input = trim($input);
        
        // Remove control characters except newlines and tabs
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
        
        // Strip HTML tags
        $input = strip_tags($input);
        
        // Decode HTML entities
        $input = html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Remove potential XSS vectors
        $input = preg_replace('/javascript:/i', '', $input);
        $input = preg_replace('/vbscript:/i', '', $input);
        $input = preg_replace('/onload/i', '', $input);
        $input = preg_replace('/onerror/i', '', $input);
        $input = preg_replace('/onclick/i', '', $input);
        
        return $input;
    }
    
    /**
     * Sanitize HTML input while preserving safe tags
     */
    public static function sanitizeHtml(string $input): string
    {
        // Remove null bytes
        $input = str_replace("\0", '', $input);
        
        // Trim whitespace
        $input = trim($input);
        
        // Remove control characters except newlines and tabs
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
        
        // Allow only safe HTML tags
        $allowedTags = '<p><br><strong><em><u><ul><ol><li><h1><h2><h3><h4><h5><h6>';
        $input = strip_tags($input, $allowedTags);
        
        // Decode HTML entities
        $input = html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return $input;
    }
    
    /**
     * Validate and sanitize email input
     */
    public static function sanitizeEmail(string $email): string
    {
        $email = trim($email);
        $email = strtolower($email);
        
        // Remove any potential XSS
        $email = preg_replace('/[<>"\']/', '', $email);
        
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format');
        }
        
        return $email;
    }
    
    /**
     * Sanitize phone number input
     */
    public static function sanitizePhone(string $phone): string
    {
        // Remove all non-digit characters except + at the beginning
        $phone = preg_replace('/[^\d+]/', '', $phone);
        
        // Ensure + is only at the beginning
        if (strpos($phone, '+') !== 0 && strpos($phone, '+') !== false) {
            $phone = str_replace('+', '', $phone);
        }
        
        return $phone;
    }
}
