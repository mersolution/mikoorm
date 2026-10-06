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

/**
 * Validates a model using the attributes on its properties.
 *
 * Declare the properties protected so magic attribute access keeps working:
 *
 *   #[Required, MaxLength(100)]
 *   protected $Name;
 *
 *   $validator = new ModelValidator();
 *   if (!$validator->validate($user)) { $errors = $validator->getErrors(); }
 */
class ModelValidator
{
    private const RULES = [
        Required::class, MaxLength::class, MinLength::class, Email::class, Url::class, Numeric::class,
        Integer::class, Min::class, Max::class, Range::class, Pattern::class, In::class, NotIn::class,
        UniqueValue::class, Exists::class, Confirmed::class, Date::class, Before::class, After::class,
        Phone::class, CreditCard::class, Json::class,
    ];

    private array $errors = [];
    private ?Model $model = null;

    /**
     * Validate a model
     */
    public function validate(Model $model): bool
    {
        $this->model = $model;
        $this->errors = [];

        $internal = ModelMetadata::internalProperties();

        foreach ((new \ReflectionClass($model))->getProperties() as $property) {
            if ($property->isStatic() || isset($internal[$property->getName()])) {
                continue;
            }
            $this->validateProperty($property, $model);
        }

        return empty($this->errors);
    }

    /**
     * Validate a single property
     */
    private function validateProperty(\ReflectionProperty $property, Model $model): void
    {
        $rules = [];
        foreach ($property->getAttributes() as $attribute) {
            if (in_array($attribute->getName(), self::RULES, true)) {
                $rules[] = $attribute->newInstance();
            }
        }

        if ($rules === []) {
            return;
        }

        $propertyName = $property->getName();
        $value = $this->readValue($property, $model);

        foreach ($rules as $rule) {
            $this->validateAttribute($propertyName, $value, $rule);
        }
    }

    /**
     * Model attribute value, or the declared property value when the attribute is not set
     */
    private function readValue(\ReflectionProperty $property, Model $model): mixed
    {
        $name = $property->getName();

        if (array_key_exists($name, $model->getAttributes())) {
            return $model->getAttributeValue($name);
        }

        return $property->isInitialized($model) ? $property->getValue($model) : null;
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
            $attribute instanceof Confirmed => $this->validateConfirmed($property, $value, $attribute),
            $attribute instanceof CreditCard => $this->validateCreditCard($value),
            $attribute instanceof UniqueValue => $this->validateUnique($property, $value, $attribute),
            $attribute instanceof Exists => $this->validateExists($value, $attribute),
            $attribute instanceof Before => $this->validateBefore($value, $attribute->date),
            $attribute instanceof After => $this->validateAfter($value, $attribute->date),
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
        if (!is_scalar($value)) return false;
        return preg_match($regex, (string) $value) === 1;
    }

    private function validateIn(mixed $value, array $values): bool
    {
        if ($value === null) return true;
        return $this->inList($value, $values);
    }

    private function validateNotIn(mixed $value, array $values): bool
    {
        if ($value === null) return true;
        return !$this->inList($value, $values);
    }

    /**
     * Strict match, but "5" from a form equals 5 in the list
     */
    private function inList(mixed $value, array $values): bool
    {
        if (in_array($value, $values, true)) {
            return true;
        }

        if (is_scalar($value)) {
            $scalars = array_map(fn($v) => is_scalar($v) ? (string) $v : null, $values);
            return in_array((string) $value, $scalars, true);
        }

        return false;
    }

    private function validateDate(mixed $value, ?string $format): bool
    {
        if ($value === null || $value === '') return true;
        if ($value instanceof \DateTimeInterface) return true;
        if (!is_string($value)) return false;

        if ($format) {
            $d = \DateTime::createFromFormat($format, $value);
            return $d && $d->format($format) === $value;
        }

        return strtotime($value) !== false;
    }

    private function validateBefore(mixed $value, string $date): bool
    {
        if ($value === null || $value === '') return true;
        $time = $value instanceof \DateTimeInterface ? $value->getTimestamp() : strtotime((string) $value);
        $limit = strtotime($date);
        return $time !== false && $limit !== false && $time < $limit;
    }

    private function validateAfter(mixed $value, string $date): bool
    {
        if ($value === null || $value === '') return true;
        $time = $value instanceof \DateTimeInterface ? $value->getTimestamp() : strtotime((string) $value);
        $limit = strtotime($date);
        return $time !== false && $limit !== false && $time > $limit;
    }

    private function validatePhone(mixed $value): bool
    {
        if ($value === null || $value === '') return true;
        if (!is_scalar($value)) return false;
        return preg_match('/^[\d\s\-\+\(\)]+$/', (string) $value) === 1;
    }

    private function validateJson(mixed $value): bool
    {
        if ($value === null || $value === '') return true;
        if (!is_string($value)) return is_array($value);
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function validateConfirmed(string $property, mixed $value, Confirmed $attribute): bool
    {
        $confirmField = $attribute->confirmationField ?? ($property . '_confirmation');
        return $value === $this->model->getAttributeValue($confirmField);
    }

    private function validateCreditCard(mixed $value): bool
    {
        if ($value === null || $value === '') return true;
        $number = preg_replace('/\D/', '', (string) $value);
        if (strlen($number) < 13 || strlen($number) > 19) {
            return false;
        }
        $sum = 0;
        $parity = strlen($number) % 2;
        for ($i = 0, $len = strlen($number); $i < $len; $i++) {
            $digit = (int) $number[$i];
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

    private function validateUnique(string $property, mixed $value, UniqueValue $attribute): bool
    {
        if ($value === null || $value === '' || $this->model === null) {
            return true;
        }

        $connection = $this->model->getConnection();
        $grammar = $connection->getGrammar();
        $table = $grammar->wrapTable($attribute->table ?? $this->model::getTable());
        $column = $grammar->wrap(\Miko\Database\Query\Grammar::assertReference($attribute->column ?? $property));
        $sql = "SELECT 1 FROM {$table} WHERE {$column} = ?";
        $params = [$value];

        $key = $this->model->getKey();
        if ($this->model->exists && $key !== null && $key !== '') {
            $ignore = $grammar->wrap(\Miko\Database\Query\Grammar::assertReference($attribute->ignoreColumn ?? $this->model->getPrimaryKey()));
            $sql .= " AND {$ignore} <> ?";
            $params[] = $key;
        }

        return $connection->execute($sql . $grammar->compileLimit(1, null, false), $params)->first() === null;
    }

    private function validateExists(mixed $value, Exists $attribute): bool
    {
        if ($value === null || $value === '' || $this->model === null) {
            return true;
        }

        $connection = $this->model->getConnection();
        $grammar = $connection->getGrammar();
        $sql = 'SELECT 1 FROM ' . $grammar->wrapTable($attribute->table)
            . ' WHERE ' . $grammar->wrap(\Miko\Database\Query\Grammar::assertReference($attribute->column)) . ' = ?'
            . $grammar->compileLimit(1, null, false);

        return $connection->execute($sql, [$value])->first() !== null;
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
