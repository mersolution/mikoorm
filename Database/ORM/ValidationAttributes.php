<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * 
 * ValidationAttributes - Model validation using PHP 8 Attributes
 * Similar to mersolutionCore ValidationAttributes.cs
 */

namespace Miko\Database\ORM;

use Attribute;

// ============================================
// Validation Attributes
// ============================================

#[Attribute(Attribute::TARGET_PROPERTY)]
class Required
{
    public function __construct(
        public string $message = 'This field is required'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class MaxLength
{
    public function __construct(
        public int $length,
        public string $message = 'Maximum length is {length} characters'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class MinLength
{
    public function __construct(
        public int $length,
        public string $message = 'Minimum length is {length} characters'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Email
{
    public function __construct(
        public string $message = 'Invalid email address'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Url
{
    public function __construct(
        public string $message = 'Invalid URL'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Numeric
{
    public function __construct(
        public string $message = 'Must be a number'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Integer
{
    public function __construct(
        public string $message = 'Must be an integer'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Min
{
    public function __construct(
        public int|float $value,
        public string $message = 'Minimum value is {value}'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Max
{
    public function __construct(
        public int|float $value,
        public string $message = 'Maximum value is {value}'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Range
{
    public function __construct(
        public int|float $min,
        public int|float $max,
        public string $message = 'Value must be between {min} and {max}'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Pattern
{
    public function __construct(
        public string $regex,
        public string $message = 'Invalid format'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class In
{
    public function __construct(
        public array $values,
        public string $message = 'Invalid value'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class NotIn
{
    public function __construct(
        public array $values,
        public string $message = 'Invalid value'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class UniqueValue
{
    public function __construct(
        public ?string $table = null,
        public ?string $column = null,
        public ?string $ignoreColumn = null,
        public string $message = 'This value already exists'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Exists
{
    public function __construct(
        public string $table,
        public string $column = 'id',
        public string $message = 'Referenced record does not exist'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Confirmed
{
    public function __construct(
        public ?string $confirmationField = null,
        public string $message = 'Confirmation does not match'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Date
{
    public function __construct(
        public ?string $format = null,
        public string $message = 'Invalid date'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Before
{
    public function __construct(
        public string $date,
        public string $message = 'Date must be before {date}'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class After
{
    public function __construct(
        public string $date,
        public string $message = 'Date must be after {date}'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Phone
{
    public function __construct(
        public string $message = 'Invalid phone number'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class CreditCard
{
    public function __construct(
        public string $message = 'Invalid credit card number'
    ) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
class Json
{
    public function __construct(
        public string $message = 'Invalid JSON'
    ) {}
}

// ============================================
// Model Validator
// ============================================

class ModelValidator
{
    private array $errors = [];
    private ?Model $model = null;

    /**
     * Validate a model
     */
    public function validate(Model $model): bool
    {
        $this->model = $model;
        $this->errors = [];

        $reflection = new \ReflectionClass($model);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $this->validateProperty($property, $model);
        }

        return empty($this->errors);
    }

    /**
     * Validate a single property
     */
    private function validateProperty(\ReflectionProperty $property, Model $model): void
    {
        $attributes = $property->getAttributes();
        $propertyName = $property->getName();
        
        // Get value using getAttribute if available
        $value = $model->getAttribute($propertyName);

        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();
            $this->validateAttribute($propertyName, $value, $instance);
        }
    }

    /**
     * Validate a single attribute
     */
    private function validateAttribute(string $property, mixed $value, object $attribute): void
    {
        $valid = match (true) {
            $attribute instanceof Required => $this->validateRequired($value),
            $attribute instanceof MaxLength => $this->validateMaxLength($value, $attribute->length),
            $attribute instanceof MinLength => $this->validateMinLength($value, $attribute->length),
            $attribute instanceof Email => $this->validateEmail($value),
            $attribute instanceof Url => $this->validateUrl($value),
            $attribute instanceof Numeric => $this->validateNumeric($value),
            $attribute instanceof Integer => $this->validateInteger($value),
            $attribute instanceof Min => $this->validateMin($value, $attribute->value),
            $attribute instanceof Max => $this->validateMax($value, $attribute->value),
            $attribute instanceof Range => $this->validateRange($value, $attribute->min, $attribute->max),
            $attribute instanceof Pattern => $this->validatePattern($value, $attribute->regex),
            $attribute instanceof In => $this->validateIn($value, $attribute->values),
            $attribute instanceof NotIn => $this->validateNotIn($value, $attribute->values),
            $attribute instanceof Date => $this->validateDate($value, $attribute->format),
            $attribute instanceof Phone => $this->validatePhone($value),
            $attribute instanceof Json => $this->validateJson($value),
            default => true
        };

        if (!$valid) {
            $message = $this->formatMessage($attribute->message, $attribute);
            $this->addError($property, $message);
        }
    }

    private function validateRequired(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private function validateMaxLength(mixed $value, int $length): bool
    {
        if ($value === null) return true;
        return mb_strlen((string) $value) <= $length;
    }

    private function validateMinLength(mixed $value, int $length): bool
    {
        if ($value === null) return true;
        return mb_strlen((string) $value) >= $length;
    }

    private function validateEmail(mixed $value): bool
    {
        if ($value === null || $value === '') return true;
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function validateUrl(mixed $value): bool
    {
        if ($value === null || $value === '') return true;
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private function validateNumeric(mixed $value): bool
    {
        if ($value === null || $value === '') return true;
        return is_numeric($value);
    }

    private function validateInteger(mixed $value): bool
    {
        if ($value === null || $value === '') return true;
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    private function validateMin(mixed $value, int|float $min): bool
    {
        if ($value === null || $value === '') return true;
        return (float) $value >= $min;
    }

    private function validateMax(mixed $value, int|float $max): bool
    {
        if ($value === null || $value === '') return true;
        return (float) $value <= $max;
    }

    private function validateRange(mixed $value, int|float $min, int|float $max): bool
    {
        if ($value === null || $value === '') return true;
        $val = (float) $value;
        return $val >= $min && $val <= $max;
    }

    private function validatePattern(mixed $value, string $regex): bool
    {
        if ($value === null || $value === '') return true;
        return preg_match($regex, (string) $value) === 1;
    }

    private function validateIn(mixed $value, array $values): bool
    {
        if ($value === null) return true;
        return in_array($value, $values, true);
    }

    private function validateNotIn(mixed $value, array $values): bool
    {
        if ($value === null) return true;
        return !in_array($value, $values, true);
    }

    private function validateDate(mixed $value, ?string $format): bool
    {
        if ($value === null || $value === '') return true;
        
        if ($format) {
            $d = \DateTime::createFromFormat($format, $value);
            return $d && $d->format($format) === $value;
        }
        
        return strtotime($value) !== false;
    }

    private function validatePhone(mixed $value): bool
    {
        if ($value === null || $value === '') return true;
        return preg_match('/^[\d\s\-\+\(\)]+$/', $value) === 1;
    }

    private function validateJson(mixed $value): bool
    {
        if ($value === null || $value === '') return true;
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function formatMessage(string $message, object $attribute): string
    {
        $replacements = [];
        
        foreach (get_object_vars($attribute) as $key => $value) {
            if ($key !== 'message' && !is_array($value)) {
                $replacements["{{$key}}"] = $value;
            }
        }
        
        return strtr($message, $replacements);
    }

    private function addError(string $property, string $message): void
    {
        if (!isset($this->errors[$property])) {
            $this->errors[$property] = [];
        }
        $this->errors[$property][] = $message;
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get first error for a property
     */
    public function getFirstError(string $property): ?string
    {
        return $this->errors[$property][0] ?? null;
    }

    /**
     * Check if property has errors
     */
    public function hasError(string $property): bool
    {
        return isset($this->errors[$property]);
    }

    /**
     * Get all error messages as flat array
     */
    public function getAllMessages(): array
    {
        $messages = [];
        foreach ($this->errors as $propertyErrors) {
            foreach ($propertyErrors as $error) {
                $messages[] = $error;
            }
        }
        return $messages;
    }
}
