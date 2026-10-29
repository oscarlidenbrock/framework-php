<?php

declare(strict_types=1);

use Framework\Field\FieldDefinition;
use Framework\Field\FieldTypeRegistry;
use PHPUnit\Framework\TestCase;

final class FieldTest extends TestCase
{
    public function testBuiltInTypesValidateAndConvertValues(): void
    {
        $registry = new FieldTypeRegistry();
        foreach (['string', 'text', 'integer', 'bigint', 'decimal', 'float', 'boolean', 'date', 'datetime'] as $name) {
            self::assertSame($name, $registry->get($name)->id());
        }
        $decimal = new FieldDefinition('price', 'Price', 'decimal', false, null, ['precision' => 10, 'scale' => 2]);
        self::assertSame('12345678.90', $registry->get('decimal')->toDatabase('12345678.90', $decimal));
        self::assertSame('12345678.90', $registry->get('decimal')->fromDatabase('12345678.90', $decimal));
        $integer = new FieldDefinition('stock', 'Stock', 'integer');
        self::assertSame(42, $registry->get('integer')->normalize('42', $integer));
        $boolean = new FieldDefinition('available', 'Available', 'boolean');
        self::assertFalse($registry->get('boolean')->normalize(0, $boolean));
        self::assertFalse($registry->get('boolean')->fromDatabase('f', $boolean));
        self::assertTrue($registry->get('boolean')->fromDatabase('t', $boolean));
        self::assertNull($registry->get('boolean')->normalize(null, $boolean));
        $date = new FieldDefinition('event_date', 'Event date', 'date');
        self::assertSame('2026-10-29', $registry->get('date')->toDatabase('2026-10-29', $date));
        $datetime = new FieldDefinition('event_time', 'Event time', 'datetime');
        self::assertInstanceOf(DateTimeImmutable::class, $registry->get('datetime')->fromDatabase('2026-10-29 12:00:00', $datetime));
    }

    public function testInvalidConfigurationsAndValuesAreRejected(): void
    {
        $registry = new FieldTypeRegistry();
        foreach ([['string', ['length' => 0]], ['decimal', ['precision' => 2, 'scale' => 3]], ['text', ['length' => 10]]] as [$name, $settings]) {
            try {
                $registry->get($name)->validateSettings($settings);
                self::fail("Expected invalid {$name} settings.");
            } catch (InvalidArgumentException) {
            }
        }
        foreach ([
            ['string', new FieldDefinition('title', 'Title', 'string', true, null, ['length' => 5]), '       '],
            ['string', new FieldDefinition('title', 'Title', 'string', true, null, ['length' => 5]), 'long title'],
            ['integer', new FieldDefinition('stock', 'Stock', 'integer'), 2147483648],
            ['bigint', new FieldDefinition('owner', 'Owner', 'bigint', true), -1],
            ['decimal', new FieldDefinition('price', 'Price', 'decimal', false, null, ['precision' => 5, 'scale' => 2]), '1234.56'],
            ['boolean', new FieldDefinition('flag', 'Flag', 'boolean'), 'yes'],
            ['date', new FieldDefinition('event_date', 'Event date', 'date'), '2026-02-30'],
        ] as [$type, $field, $value]) {
            try {
                $registry->get($type)->normalize($value, $field);
                self::fail("Expected invalid {$type} value.");
            } catch (InvalidArgumentException) {
            }
        }
        $this->expectExceptionMessage('Unknown field type');
        $registry->get('unknown');
    }

    public function testRegistryRejectsDuplicateTypes(): void
    {
        $registry = new FieldTypeRegistry();
        $this->expectExceptionMessage('Duplicate or invalid field type');
        $registry->register($registry->get('string'));
    }
}
