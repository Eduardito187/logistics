<?php

declare(strict_types=1);

namespace Commerce\Accounts\Domain\ValueObject;

/**
 * Tipo de cuenta comercial (concepto de comercio, NO de identidad/authN — eso lo resuelve `access`).
 *
 * - Person: persona natural que compra en el storefront (B2C).
 * - Company: empresa con NIT que compra (B2B); el taxId es obligatorio.
 * - SellerPerson: persona que además vende en el marketplace (C2C), referenciada por `sellers`.
 */
enum AccountType: string
{
    case Person = 'person';
    case Company = 'company';
    case SellerPerson = 'seller_person';
}
