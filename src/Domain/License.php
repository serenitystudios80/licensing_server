<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * License domain value object.
 *
 * Plain PHP object representing a row from the `licenses` table.
 * This is not an ORM entity — just a typed container for row data with
 * readonly properties, used for hydration from database queries.
 *
 * Per Requirements 1.1, 1.2, 1.10 and design.md Data Models section.
 */
final readonly class License
{
    public function __construct(
        public int $id,
        public string $licenseKey,
        public string $email,
        public string $customerName,
        public string $product,
        public string $tier,                      // 'annual' | 'lifetime'
        public string $status,                    // 'active' | 'grace' | 'expired' | 'revoked'
        public string $purchasedAt,               // DATETIME string from DB (UTC)
        public ?string $expiresAt,                // DATETIME string from DB (UTC), nullable
        public ?string $graceStartAt,             // DATETIME string from DB (UTC), nullable
        public ?string $razorpaySubscriptionId,   // nullable
        public int $activationLimit,              // positive integer >= 1
        public ?string $priceAmount,              // DECIMAL as string, nullable
        public ?string $currency,                 // 3-letter code, nullable
        public string $notes,                     // max 2000 chars, defaults to ''
        public string $createdAt,                 // DATETIME string from DB (UTC)
        public string $updatedAt,                 // DATETIME string from DB (UTC)
    ) {
    }

    /**
     * Hydrate a License instance from a database row (associative array).
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            licenseKey: (string) $row['license_key'],
            email: (string) $row['email'],
            customerName: (string) $row['customer_name'],
            product: (string) $row['product'],
            tier: (string) $row['tier'],
            status: (string) $row['status'],
            purchasedAt: (string) $row['purchased_at'],
            expiresAt: $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            graceStartAt: $row['grace_start_at'] !== null ? (string) $row['grace_start_at'] : null,
            razorpaySubscriptionId: $row['razorpay_subscription_id'] !== null
                ? (string) $row['razorpay_subscription_id']
                : null,
            activationLimit: (int) $row['activation_limit'],
            priceAmount: $row['price_amount'] !== null ? (string) $row['price_amount'] : null,
            currency: $row['currency'] !== null ? (string) $row['currency'] : null,
            notes: (string) $row['notes'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
