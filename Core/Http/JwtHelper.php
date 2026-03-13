<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * 
 * JwtHelper - JWT token generation and validation
 * Similar to mersolutionCore JwtHelper.cs
 */

namespace Miko\Core\Http;

class JwtHelper
{
    private string $secretKey;
    private ?string $issuer;
    private ?string $audience;
    private int $expirationMinutes;

    /**
     * Create JWT helper
     * 
     * @param string $secretKey Secret key for signing (min 16 characters)
     * @param string|null $issuer Token issuer
     * @param string|null $audience Token audience
     * @param int $expirationMinutes Token expiration in minutes (default: 60)
     */
    public function __construct(
        string $secretKey,
        ?string $issuer = null,
        ?string $audience = null,
        int $expirationMinutes = 60
    ) {
        if (strlen($secretKey) < 16) {
            throw new \InvalidArgumentException('Secret key must be at least 16 characters');
        }

        $this->secretKey = $secretKey;
        $this->issuer = $issuer;
        $this->audience = $audience;
        $this->expirationMinutes = $expirationMinutes;
    }

    /**
     * Generate JWT token with claims
     */
    public function generateToken(array $claims): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT'
        ];

        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + ($this->expirationMinutes * 60),
            'nbf' => $now,
        ]);

        if ($this->issuer) {
            $payload['iss'] = $this->issuer;
        }

        if ($this->audience) {
            $payload['aud'] = $this->audience;
        }

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $signature = $this->sign("{$headerEncoded}.{$payloadEncoded}");
        $signatureEncoded = $this->base64UrlEncode($signature);

        return "{$headerEncoded}.{$payloadEncoded}.{$signatureEncoded}";
    }

    /**
     * Generate token for user
     */
    public function generateUserToken(
        string|int $userId,
        string $username,
        ?string $role = null,
        array $extraClaims = []
    ): string {
        $claims = array_merge([
            'sub' => (string) $userId,
            'name' => $username,
            'jti' => $this->generateJti(),
        ], $extraClaims);

        if ($role) {
            $claims['role'] = $role;
        }

        return $this->generateToken($claims);
    }

    /**
     * Generate token with multiple roles
     */
    public function generateTokenWithRoles(
        string|int $userId,
        string $username,
        array $roles,
        array $extraClaims = []
    ): string {
        $claims = array_merge([
            'sub' => (string) $userId,
            'name' => $username,
            'jti' => $this->generateJti(),
            'roles' => $roles,
        ], $extraClaims);

        return $this->generateToken($claims);
    }

    /**
     * Validate JWT token
     */
    public function validateToken(string $token): JwtValidationResult
    {
        try {
            if (empty($token)) {
                return JwtValidationResult::invalid('Token is empty');
            }

            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return JwtValidationResult::invalid('Invalid token format');
            }

            [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

            // Verify signature
            $expectedSignature = $this->base64UrlEncode(
                $this->sign("{$headerEncoded}.{$payloadEncoded}")
            );

            if (!hash_equals($expectedSignature, $signatureEncoded)) {
                return JwtValidationResult::invalid('Invalid signature');
            }

            // Decode payload
            $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
            if (!is_array($payload)) {
                return JwtValidationResult::invalid('Invalid payload');
            }

            // Check expiration
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                return JwtValidationResult::invalid('Token has expired');
            }

            // Check not before
            if (isset($payload['nbf']) && $payload['nbf'] > time()) {
                return JwtValidationResult::invalid('Token is not yet valid');
            }

            // Check issuer
            if ($this->issuer && isset($payload['iss']) && $payload['iss'] !== $this->issuer) {
                return JwtValidationResult::invalid('Invalid issuer');
            }

            // Check audience
            if ($this->audience && isset($payload['aud']) && $payload['aud'] !== $this->audience) {
                return JwtValidationResult::invalid('Invalid audience');
            }

            return JwtValidationResult::valid($payload);

        } catch (\Exception $e) {
            return JwtValidationResult::invalid('Token validation failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if token is valid (quick check)
     */
    public function isTokenValid(string $token): bool
    {
        return $this->validateToken($token)->isValid;
    }

    /**
     * Get claim value from token without full validation
     */
    public function getClaimValue(string $token, string $claimName): mixed
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            $payload = json_decode($this->base64UrlDecode($parts[1]), true);
            return $payload[$claimName] ?? null;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get token expiration timestamp
     */
    public function getTokenExpiration(string $token): ?int
    {
        $exp = $this->getClaimValue($token, 'exp');
        return is_numeric($exp) ? (int) $exp : null;
    }

    /**
     * Get token expiration as DateTime
     */
    public function getTokenExpirationDate(string $token): ?\DateTime
    {
        $exp = $this->getTokenExpiration($token);
        if ($exp === null) {
            return null;
        }
        return (new \DateTime())->setTimestamp($exp);
    }

    /**
     * Check if token is expired
     */
    public function isTokenExpired(string $token): bool
    {
        $exp = $this->getTokenExpiration($token);
        return $exp !== null && $exp < time();
    }

    /**
     * Get remaining time in seconds
     */
    public function getTokenRemainingTime(string $token): int
    {
        $exp = $this->getTokenExpiration($token);
        if ($exp === null) {
            return 0;
        }
        return max(0, $exp - time());
    }

    /**
     * Refresh token (generate new token with same claims but new expiration)
     */
    public function refreshToken(string $token): string
    {
        $result = $this->validateToken($token);
        
        if (!$result->isValid) {
            throw new \InvalidArgumentException('Cannot refresh invalid token: ' . $result->errorMessage);
        }

        // Remove time-related claims
        $claims = $result->claims;
        unset($claims['iat'], $claims['exp'], $claims['nbf'], $claims['jti'], $claims['iss'], $claims['aud']);

        // Generate new jti
        $claims['jti'] = $this->generateJti();

        return $this->generateToken($claims);
    }

    /**
     * Decode token without validation (for debugging)
     */
    public function decodeToken(string $token): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            return [
                'header' => json_decode($this->base64UrlDecode($parts[0]), true),
                'payload' => json_decode($this->base64UrlDecode($parts[1]), true),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Sign data with HMAC-SHA256
     */
    private function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secretKey, true);
    }

    /**
     * Base64 URL encode
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode
     */
    private function base64UrlDecode(string $data): string
    {
        $padding = strlen($data) % 4;
        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Generate unique token ID
     */
    private function generateJti(): string
    {
        return bin2hex(random_bytes(16));
    }
}

/**
 * JWT Validation Result
 */
class JwtValidationResult
{
    public bool $isValid = false;
    public ?string $errorMessage = null;
    public array $claims = [];

    /**
     * Get claim value
     */
    public function getClaim(string $name): mixed
    {
        return $this->claims[$name] ?? null;
    }

    /**
     * Get user ID (sub claim)
     */
    public function getUserId(): ?string
    {
        return $this->getClaim('sub');
    }

    /**
     * Get username (name claim)
     */
    public function getUsername(): ?string
    {
        return $this->getClaim('name');
    }

    /**
     * Get role (role claim)
     */
    public function getRole(): ?string
    {
        return $this->getClaim('role');
    }

    /**
     * Get roles (roles claim)
     */
    public function getRoles(): array
    {
        $roles = $this->getClaim('roles');
        return is_array($roles) ? $roles : [];
    }

    /**
     * Check if user has role
     */
    public function hasRole(string $role): bool
    {
        $singleRole = $this->getRole();
        if ($singleRole === $role) {
            return true;
        }
        return in_array($role, $this->getRoles());
    }

    /**
     * Create valid result
     */
    public static function valid(array $claims): self
    {
        $result = new self();
        $result->isValid = true;
        $result->claims = $claims;
        return $result;
    }

    /**
     * Create invalid result
     */
    public static function invalid(string $errorMessage): self
    {
        $result = new self();
        $result->isValid = false;
        $result->errorMessage = $errorMessage;
        return $result;
    }
}
