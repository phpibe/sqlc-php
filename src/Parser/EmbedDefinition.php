<?php

declare(strict_types=1);

namespace SqlcPhp\Parser;

/**
 * Represents a single @embed annotation on a query.
 *
 * @embed ClassName prefix
 *
 * Groups all result columns whose alias starts with `prefix_` into a nested
 * readonly object of type `ClassName` inside the result DTO.
 *
 * Examples:
 *   -- @embed Role role_
 *       role_name, role_description → Role { $name, $description }
 *       DTO property: public Role $role
 *
 *   -- @embed Address billing_
 *       billing_street, billing_city → Address { $street, $city }
 *       DTO property: public Address $billing
 *
 * The generated property name on the parent DTO is derived from the prefix
 * by stripping the trailing underscore and lowercasing:
 *   "role_"     → $role
 *   "billing_"  → $billing
 */
readonly class EmbedDefinition
{
    public function __construct(
        /** PHP class name for the nested object, e.g. "Role" */
        public string $className,
        /**
         * Column alias prefix (with or without trailing underscore).
         * Stored normalised — always with a trailing underscore.
         * e.g. "role_", "billing_"
         */
        public string $prefix,
    ) {}

    /**
     * The PHP property name on the parent DTO, derived from the prefix.
     * "role_" → "role", "billing_" → "billing"
     */
    public function propertyName(): string
    {
        return rtrim($this->prefix, '_');
    }

    /**
     * Returns true when the given column alias belongs to this embed group.
     */
    public function matches(string $alias): bool
    {
        return str_starts_with($alias, $this->prefix);
    }

    /**
     * Strip the prefix from a column alias to get the nested property name.
     * "role_name" → "name"
     */
    public function stripPrefix(string $alias): string
    {
        return substr($alias, strlen($this->prefix));
    }
}
