<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Core\Validation;

/**
 * Validator - Input validation utilities
 */
class Validator
{
    private array $errors = [];
    private array $data = [];

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * Validate data against rules
     */
    public function validate(array $rules): bool
    {
        $this->errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $this->data[$field] ?? null;
            $ruleList = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;

            foreach ($ruleList as $rule) {
                $this->applyRule($field, $value, $rule);
            }
        }

        return empty($this->errors);
    }

    /**
     * Apply a single rule
     */
    private function applyRule(string $field, mixed $value, string $rule): void
    {
        $params = [];
        if (str_contains($rule, ':')) {
            [$rule, $paramStr] = explode(':', $rule, 2);
            $params = explode(',', $paramStr);
        }

        $method = 'validate' . ucfirst($rule);
        if (method_exists($this, $method)) {
            if (!$this->$method($value, $params)) {
                $this->addError($field, $rule, $params);
            }
        }
    }

    /**
     * Add validation error
     */
    private function addError(string $field, string $rule, array $params = []): void
    {
        $messages = [
            'required' => '{field} is required',
            'email' => '{field} must be a valid email address',
            'min' => '{field} must be at least {0} characters',
            'max' => '{field} must not exceed {0} characters',
            'numeric' => '{field} must be a numeric value',
            'integer' => '{field} must be an integer',
            'phone' => '{field} must be a valid phone number',
            'date' => '{field} must be a valid date',
            'url' => '{field} must be a valid URL',
            'in' => '{field} must be a valid value',
            'confirmed' => '{field} confirmation does not match',
            'regex' => '{field} format is invalid',
            'iban' => '{field} must be a valid IBAN number',
            'uuid' => '{field} must be a valid UUID',
            'creditCard' => '{field} must be a valid credit card number',
            'ipv4' => '{field} must be a valid IPv4 address',
            'ipv6' => '{field} must be a valid IPv6 address',
            'ip' => '{field} must be a valid IP address',
            'mac' => '{field} must be a valid MAC address',
            'bic' => '{field} must be a valid BIC/SWIFT code'
        ];

        $message = $messages[$rule] ?? "{$field} is invalid";
        $message = str_replace('{field}', $field, $message);

        foreach ($params as $i => $param) {
            $message = str_replace("{{$i}}", $param, $message);
        }

        $this->errors[$field][] = $message;
    }

    /**
     * Get validation errors
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get first error for field
     */
    public function firstError(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    /**
     * Check if field has error
     */
    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]);
    }

    // Validation Rules

    private function validateRequired(mixed $value): bool
    {
        if (is_null($value)) return false;
        if (is_string($value) && trim($value) === '') return false;
        if (is_array($value) && empty($value)) return false;
        return true;
    }

    private function validateEmail(mixed $value): bool
    {
        if (empty($value)) return true;
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function validateMin(mixed $value, array $params): bool
    {
        if (empty($value)) return true;
        $min = (int)($params[0] ?? 0);
        return mb_strlen((string)$value) >= $min;
    }

    private function validateMax(mixed $value, array $params): bool
    {
        if (empty($value)) return true;
        $max = (int)($params[0] ?? 0);
        return mb_strlen((string)$value) <= $max;
    }

    private function validateNumeric(mixed $value): bool
    {
        if (empty($value)) return true;
        return is_numeric($value);
    }

    private function validateInteger(mixed $value): bool
    {
        if (empty($value)) return true;
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    private function validatePhone(mixed $value): bool
    {
        if (empty($value)) return true;
        $digits = preg_replace('/\D/', '', $value);
        return strlen($digits) >= 10 && strlen($digits) <= 11;
    }

    private function validateDate(mixed $value): bool
    {
        if (empty($value)) return true;
        return strtotime($value) !== false;
    }

    private function validateUrl(mixed $value): bool
    {
        if (empty($value)) return true;
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private function validateIn(mixed $value, array $params): bool
    {
        if (empty($value)) return true;
        return in_array($value, $params);
    }

    private function validateConfirmed(mixed $value, array $params): bool
    {
        $confirmField = $params[0] ?? '';
        return $value === ($this->data[$confirmField] ?? null);
    }

    private function validateRegex(mixed $value, array $params): bool
    {
        if (empty($value)) return true;
        $pattern = $params[0] ?? '';
        return preg_match($pattern, $value) === 1;
    }

    private function validateIban(mixed $value): bool
    {
        if (empty($value)) return true;
        return self::isValidIBAN($value);
    }

    private function validateUuid(mixed $value): bool
    {
        if (empty($value)) return true;
        return self::isValidUUID($value);
    }

    private function validateCreditCard(mixed $value): bool
    {
        if (empty($value)) return true;
        return self::isValidCreditCard($value);
    }

    private function validateIpv4(mixed $value): bool
    {
        if (empty($value)) return true;
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private function validateIpv6(mixed $value): bool
    {
        if (empty($value)) return true;
        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    private function validateIp(mixed $value): bool
    {
        if (empty($value)) return true;
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    private function validateMac(mixed $value): bool
    {
        if (empty($value)) return true;
        return self::isValidMAC($value);
    }

    private function validateBic(mixed $value): bool
    {
        if (empty($value)) return true;
        return self::isValidBIC($value);
    }

    // Static validation methods

    public static function isEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function isPhone(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value);
        return strlen($digits) >= 10 && strlen($digits) <= 11;
    }

    public static function isRequired(mixed $value): bool
    {
        if (is_null($value)) return false;
        if (is_string($value) && trim($value) === '') return false;
        if (is_array($value) && empty($value)) return false;
        return true;
    }

    public static function isValidIBAN(string $iban): bool
    {
        $iban = strtoupper(str_replace(' ', '', $iban));
        
        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return false;
        }
        
        $iban = substr($iban, 4) . substr($iban, 0, 4);
        $ibanNumeric = '';

        foreach (str_split($iban) as $char) {
            if (ctype_alpha($char)) {
                $ibanNumeric .= ord($char) - 55;
            } else {
                $ibanNumeric .= $char;
            }
        }

        return bcmod($ibanNumeric, '97') === '1';
    }

    public static function isValidUUID(string $uuid): bool
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        return preg_match($pattern, $uuid) === 1;
    }

    public static function isValidCreditCard(string $number): bool
    {
        $number = preg_replace('/\D/', '', $number);
        
        if (strlen($number) < 13 || strlen($number) > 19) {
            return false;
        }

        // Luhn algorithm
        $sum = 0;
        $length = strlen($number);
        $parity = $length % 2;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int)$number[$i];
            
            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    public static function isValidMAC(string $mac): bool
    {
        $pattern = '/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/'; 
        return preg_match($pattern, $mac) === 1;
    }

    public static function isValidBIC(string $bic): bool
    {
        // BIC/SWIFT: 8 or 11 characters (bank code + country + location + optional branch)
        $pattern = '/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/i';
        return preg_match($pattern, strtoupper($bic)) === 1;
    }
}
